<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoiceIssuedImmutable;
use App\Domain\Invoices\Exceptions\InvoiceIssueIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceIssueSequenceUnavailable;
use App\Domain\Invoices\Exceptions\InvoiceIssueVersionConflict;
use App\Domain\Invoices\Exceptions\InvoiceNotDraft;
use App\Domain\Invoices\Exceptions\InvoiceNotReadyForIssue;
use App\Enums\BusinessConnection;
use App\Http\Requests\IssueInvoiceRequest;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePublicLink;
use App\Models\Business\VatRate;
use App\Models\User;
use App\Services\Business\InvoiceDraftEditor;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoiceReader;
use App\Services\Business\InvoiceRevisionFactory;
use App\Services\Business\VatRateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceIssuerTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use InteractsWithBusinessDatabases;

    protected array $businessDatabaseTransactionExclusions = [
        'test_valid_draft_is_issued_once_and_becomes_strictly_immutable',
        'test_readiness_and_audit_failure_leave_draft_numbering_untouched',
        'test_two_processes_issue_same_draft_exactly_once',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_valid_draft_is_issued_once_and_becomes_strictly_immutable(): void
    {
        $business = $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $sequence = $this->sequence(default: true);
        $invoice = $this->draft($client, $account, $rate);
        $revisionId = $invoice->current_revision_id;
        $correlation = (string) Str::uuid();

        $issued = app(InvoiceIssuer::class)->issue($invoice->uuid, 1, $correlation);

        $this->assertSame('issued', $issued->status->value);
        $this->assertSame('FV-202600001', $issued->document_number);
        $this->assertSame(2, $issued->version);
        $this->assertSame($revisionId, $issued->issued_revision_id);
        $this->assertSame($revisionId, $issued->current_revision_id);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame($invoice->uuid, $issued->numberAllocation->document_uuid);
        $this->assertSame($correlation, $issued->numberAllocation->correlation_uuid);
        $this->assertSame(2, $sequence->fresh()->next_number);
        $this->assertSame(1, InvoicePublicLink::query()->active()->where('invoice_id', $issued->id)->count());

        $again = app(InvoiceIssuer::class)->issue($invoice->uuid, 1, $correlation);
        $this->assertSame($issued->document_number, $again->document_number);
        $this->assertSame($issued->issued_at->format('c'), $again->issued_at->format('c'));
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued')->count());
        $this->assertSame(1, InvoicePublicLink::query()->where('invoice_id', $issued->id)->count());

        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued')->first();
        $serialized = (string) $audit->new_values.(string) $audit->metadata;
        $this->assertStringContainsString($issued->document_number, $serialized);
        $this->assertStringNotContainsString('klient@example.test', $serialized);
        $this->assertStringNotContainsString('CZ6508000000192000145399', $serialized);
        $this->assertStringNotContainsString('business_1', $serialized);

        $read = app(InvoiceReader::class)->find($invoice->uuid);
        $this->assertTrue($read->relationLoaded('issuedRevision'));
        $this->assertSame($revisionId, $read->issuedRevision->id);
        $this->assertFalse($read->relationLoaded('currentRevision'));
        $this->assertTrue(app(VatRateService::class)->hasIssuedDocumentUsage($rate));

        try {
            app(VatRateService::class)->update($rate->uuid, [
                'name' => 'Přepsaná historická sazba',
                'code' => 'OUT',
                'tax_type' => 'out_of_scope',
                'percentage' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
                'sort_order' => 0,
            ]);
            $this->fail('Historická pole použité sazby měla být uzamčena.');
        } catch (ValidationException) {
            $this->assertSame('Mimo DPH', $rate->fresh()->name);
        }

        try {
            app(InvoiceDraftEditor::class)->update($invoice->uuid, 2, (string) Str::uuid(), $this->payload($client, $account, $rate));
            $this->fail('Vystavenou fakturu nemělo být možné editovat.');
        } catch (InvoiceNotDraft) {
            $this->assertSame(1, Invoice::query()->where('uuid', $invoice->uuid)->count());
        }

        try {
            $issued->forceFill(['document_number' => 'PREPIS'])->save();
            $this->fail('Model neměl povolit změnu vystavené faktury.');
        } catch (InvoiceIssuedImmutable) {
            $this->assertSame('FV-202600001', $issued->fresh()->document_number);
        }

        foreach ([
            fn () => DB::connection('business_1')->table('invoices')->where('id', $issued->id)->update(['document_number' => 'PREPIS']),
            fn () => DB::connection('business_1')->table('invoices')->where('id', $issued->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Databázový trigger měl mutaci odmítnout.');
            } catch (QueryException) {
                $this->assertDatabaseHas('invoices', ['id' => $issued->id, 'status' => 'issued'], 'business_1');
            }
        }

        try {
            DB::connection('business_1')->transaction(
                fn () => app(InvoiceRevisionFactory::class)->persist($issued, 3, []),
            );
            $this->fail('Vystavená faktura neměla přijmout další revizi.');
        } catch (InvoiceIssuedImmutable) {
            $this->assertSame(1, $issued->revisions()->count());
        }

        try {
            DB::connection('business_1')->table('document_number_allocations')
                ->where('id', $issued->document_number_allocation_id)
                ->update(['formatted_number' => 'PREPIS']);
            $this->fail('Allocation ledger měl být neměnný i v databázi.');
        } catch (QueryException) {
            $this->assertSame('FV-202600001', $issued->numberAllocation->fresh()->formatted_number);
        }

        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $admin->businesses()->attach($business, ['role' => 'admin']);
        $viewer->businesses()->attach($business, ['role' => 'viewer']);
        $this->actingAs($admin);
        $this->assertTrue(Gate::allows('issue', Invoice::class));
        $this->actingAs($viewer);
        $this->assertFalse(Gate::allows('issue', Invoice::class));

        $rules = (new IssueInvoiceRequest)->rules();
        foreach (['status', 'document_number', 'allocation_id', 'issued_revision_id', 'issued_at', 'totals', 'connection', 'business_id', 'snapshots', 'document_uuid'] as $field) {
            $this->assertContains('prohibited', $rules[$field]);
        }
    }

    public function test_non_payer_snapshot_is_recalculated_and_issued_with_zero_vat(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $ordinaryRate] = $this->sources();
        CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->update(['is_vat_payer' => false, 'vat_id' => null]);
        $this->sequence(default: true);
        $invoice = $this->draft($client, $account, $ordinaryRate);

        $snapshot = $invoice->currentRevision->vatSnapshots->sole();
        $this->assertSame('non_payer', $snapshot->tax_type->value);
        $this->assertNull($snapshot->percentage);
        $this->assertSame('0.0000', $invoice->currentRevision->vat_total);

        $issued = app(InvoiceIssuer::class)->issue($invoice->uuid, 1, (string) Str::uuid());

        $this->assertSame('issued', $issued->status->value);
        $this->assertSame('non_payer', $issued->issuedRevision->vatSnapshots->sole()->tax_type->value);
        $this->assertSame('0.0000', $issued->issuedRevision->vat_total);
    }

    public function test_readiness_and_audit_failure_leave_draft_numbering_untouched(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $sequence = $this->sequence(default: true);
        $missingBank = $this->draft($client, null, $rate);

        try {
            app(InvoiceIssuer::class)->issue($missingBank->uuid, 1, (string) Str::uuid());
            $this->fail('Bankovní převod bez snapshotu účtu měl být odmítnut.');
        } catch (InvoiceNotReadyForIssue) {
            $this->assertSame('draft', $missingBank->fresh()->status->value);
            $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
            $this->assertSame(0, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued')->count());
        }

        $missingRevision = $this->draft($client, $account, $rate);
        DB::connection('business_1')->table('invoices')->where('id', $missingRevision->id)->update(['current_revision_id' => null]);

        try {
            app(InvoiceIssuer::class)->issue($missingRevision->uuid, 1, (string) Str::uuid());
            $this->fail('Draft bez current revision měl být odmítnut.');
        } catch (InvoiceNotReadyForIssue) {
            $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
        }

        $tampered = $this->draft($client, $account, $rate);
        $this->tamperGrandTotal($tampered);

        try {
            app(InvoiceIssuer::class)->issue($tampered->uuid, 1, (string) Str::uuid());
            $this->fail('Draft s nekonzistentními totals měl být odmítnut.');
        } catch (InvoiceNotReadyForIssue) {
            $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
        }

        $valid = $this->draft($client, $account, $rate);
        Schema::connection('business_1')->drop('audit_logs');

        try {
            app(InvoiceIssuer::class)->issue($valid->uuid, 1, (string) Str::uuid());
            $this->fail('Selhání auditu mělo rollbacknout vystavení.');
        } catch (QueryException) {
            $fresh = $valid->fresh();
            $this->assertSame('draft', $fresh->status->value);
            $this->assertNull($fresh->document_number);
            $this->assertNull($fresh->issued_at);
            $this->assertSame(1, $fresh->version);
            $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
            $this->assertSame(1, $sequence->fresh()->next_number);
        }
    }

    public function test_sequences_conflicts_and_correlations_are_tenant_local(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $explicit = $this->sequence();
        $wrongType = $this->sequence(documentType: 'advance_invoice');
        $inactive = $this->sequence(prefix: 'IN-', active: false);
        $archived = $this->sequence(prefix: 'AR-', archived: true);
        $first = $this->draft($client, $account, $rate);
        $second = $this->draft($client, $account, $rate);

        try {
            app(InvoiceIssuer::class)->issue($first->uuid, 1, (string) Str::uuid(), $wrongType->uuid);
            $this->fail('Řada jiného typu měla být odmítnuta.');
        } catch (InvoiceIssueSequenceUnavailable) {
            $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
        }

        foreach ([$inactive, $archived] as $unavailable) {
            try {
                app(InvoiceIssuer::class)->issue($first->uuid, 1, (string) Str::uuid(), $unavailable->uuid);
                $this->fail('Neaktivní nebo archivovaná řada měla být odmítnuta.');
            } catch (InvoiceIssueSequenceUnavailable) {
                $this->assertSame(0, DB::connection('business_1')->table('document_number_allocations')->count());
            }
        }

        try {
            app(InvoiceIssuer::class)->issue($first->uuid, 2, (string) Str::uuid(), $explicit->uuid);
            $this->fail('Neaktuální verze měla být odmítnuta.');
        } catch (InvoiceIssueVersionConflict) {
            $this->assertSame('draft', $first->fresh()->status->value);
        }

        $correlation = (string) Str::uuid();
        $issued = app(InvoiceIssuer::class)->issue($first->uuid, 1, $correlation, $explicit->uuid);

        try {
            app(InvoiceIssuer::class)->issue($second->uuid, 1, $correlation, $explicit->uuid);
            $this->fail('Stejná korelace jiné faktury měla být odmítnuta.');
        } catch (InvoiceIssueIdempotencyConflict) {
            $this->assertSame('draft', $second->fresh()->status->value);
            $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        }

        try {
            app(InvoiceIssuer::class)->issue($first->uuid, 2, (string) Str::uuid(), $explicit->uuid);
            $this->fail('Vystavená faktura neměla přijmout jinou korelaci.');
        } catch (InvoiceNotDraft) {
            $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued')->count());
            $this->assertGreaterThanOrEqual(2, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issue_conflict')->count());
        }

        $firstUuid = $issued->uuid;
        app(ActiveBusinessContext::class)->clear();
        $this->activate(BusinessConnection::Business2);

        try {
            app(InvoiceIssuer::class)->issue($firstUuid, 1, $correlation);
            $this->fail('Cizí tenant fakturu neměl najít.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, DB::connection('business_2')->table('document_number_allocations')->count());
        }

        [$otherClient, $otherAccount, $otherRate] = $this->sources();
        $this->sequence(default: true);
        $other = $this->draft($otherClient, $otherAccount, $otherRate);
        DB::connection('business_2')->table('invoices')->where('id', $other->id)->update(['uuid' => $firstUuid]);
        $other->refresh();
        $otherIssued = app(InvoiceIssuer::class)->issue($other->uuid, 1, $correlation);

        $this->assertSame($firstUuid, $otherIssued->uuid);
        $this->assertSame($correlation, $otherIssued->issue_correlation_uuid);
        $this->assertSame(1, DB::connection('business_2')->table('document_number_allocations')->count());
        $this->assertSame(1, DB::connection('business_2')->table('audit_logs')->where('event', 'invoice.issued')->count());
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_two_processes_issue_same_draft_exactly_once(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $sequence = $this->sequence(default: true);
        $invoice = $this->draft($client, $account, $rate);
        $correlation = (string) Str::uuid();
        $barrier = storage_path('framework/testing/invoice-issue-'.Str::uuid());
        $processes = [];

        for ($index = 0; $index < 2; $index++) {
            $process = new Process($this->businessPhpCommand(
                base_path('tests/Support/issue-invoice.php'),
                [
                    'business_1',
                    $invoice->uuid,
                    '1',
                    $correlation,
                    $barrier,
                ],
            ), base_path(), $this->businessChildProcessEnvironment());
            $process->setTimeout(45);
            $processes[] = $process;
        }

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            file_put_contents($barrier, 'start');
            foreach ($processes as $process) {
                $process->wait();
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $exitCodes = array_map(fn (Process $process): int => $process->getExitCode() ?? -1, $processes);
        $this->assertSame([0, 0], $exitCodes, implode(PHP_EOL, array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
        $fresh = $invoice->fresh();
        $this->assertSame('issued', $fresh->status->value);
        $this->assertSame(2, $fresh->version);
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued')->count());
        $this->assertSame(2, $sequence->fresh()->next_number);
    }

    private function activate(BusinessConnection $connection): Business
    {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(),
            'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(),
            'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    private function tamperGrandTotal(Invoice $invoice): void
    {
        $database = DB::connection('business_1');
        $database->unprepared('DROP TRIGGER `invoice_revisions_immutable_update`');

        try {
            $database->table('invoice_revisions')->where('id', $invoice->current_revision_id)
                ->update(['grand_total' => '999.0000']);
        } finally {
            $database->unprepared(<<<'SQL'
                CREATE TRIGGER `invoice_revisions_immutable_update`
                BEFORE UPDATE ON `invoice_revisions` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice revision record is immutable'
                SQL);
        }
    }

    /** @return array{Client, BankAccount, VatRate} */
    private function sources(): array
    {
        $company = new CompanySetting;
        $company->forceFill([
            'singleton_key' => '1',
            'legal_name' => 'Dodavatel s.r.o.',
            'registration_number' => '12345678',
            'tax_id' => 'CZ12345678',
            'street' => 'Dodavatelská',
            'house_number' => '10',
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'email' => 'dodavatel@example.test',
            'default_currency' => 'CZK',
            'document_locale' => 'cs',
            'timezone' => 'Europe/Prague',
            'is_vat_payer' => true,
            'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer',
        ])->save();

        $client = new Client;
        $client->forceFill([
            'type' => 'company',
            'display_name' => 'Klient',
            'company_name' => 'Klient s.r.o.',
            'registration_number' => '87654321',
            'street' => 'Klientská',
            'house_number' => '1',
            'city' => 'Brno',
            'postal_code' => '60200',
            'country_code' => 'CZ',
            'email' => 'klient@example.test',
            'default_currency' => 'CZK',
            'is_active' => true,
        ])->save();

        $account = new BankAccount;
        $account->forceFill([
            'name' => 'Hlavní účet',
            'iban' => 'CZ6508000000192000145399',
            'bic' => 'GIBACZPX',
            'currency' => 'CZK',
            'is_active' => true,
            'sort_order' => 0,
        ])->save();

        $rate = new VatRate;
        $rate->forceFill([
            'name' => 'Mimo DPH',
            'code' => 'OUT',
            'tax_type' => 'out_of_scope',
            'percentage' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'sort_order' => 0,
        ])->save();

        return [$client, $account, $rate];
    }

    private function sequence(
        bool $default = false,
        string $documentType = 'issued_invoice',
        string $prefix = 'FV-',
        bool $active = true,
        bool $archived = false,
    ): DocumentSequence {
        $sequence = new DocumentSequence;
        $sequence->forceFill([
            'document_type' => $documentType,
            'name' => 'Faktury',
            'prefix' => $prefix,
            'suffix' => '',
            'year_format' => 'yyyy',
            'sequence_digits' => 5,
            'start_number' => 1,
            'next_number' => 1,
            'reset_period' => 'yearly',
            'current_period' => null,
            'is_active' => $active,
            'sort_order' => 0,
            'archived_at' => $archived ? now() : null,
        ])->save();

        if ($default) {
            $assignment = new DocumentSequenceDefault;
            $assignment->forceFill([
                'document_type' => $documentType,
                'document_sequence_id' => $sequence->id,
            ])->save();
        }

        return $sequence;
    }

    private function draft(Client $client, ?BankAccount $account, VatRate $rate): Invoice
    {
        return app(InvoiceDraftService::class)->create($this->payload($client, $account, $rate));
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, ?BankAccount $account, VatRate $rate): array
    {
        return [
            'customer_uuid' => $client->uuid,
            'bank_account_uuid' => $account?->uuid,
            'currency' => 'CZK',
            'issued_on' => '2026-08-01',
            'taxable_supply_on' => '2026-08-01',
            'due_on' => '2026-08-15',
            'payment_method' => 'bank_transfer',
            'variable_symbol' => '20260001',
            'note' => 'Citlivá interní poznámka',
            'items' => [[
                'position' => 1,
                'description' => 'Citlivý text položky',
                'quantity' => '2.0000',
                'unit' => 'hod',
                'unit_price' => '100.0000',
                'discount_type' => 'none',
                'discount_value' => null,
                'vat_rate_uuid' => $rate->uuid,
            ]],
        ];
    }
}
