<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoiceDraftIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDraftVersionConflict;
use App\Enums\BusinessConnection;
use App\Http\Requests\UpdateInvoiceDraftRequest;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;
use App\Models\Business\VatRate;
use App\Services\Business\InvoiceDraftEditor;
use App\Services\Business\InvoiceDraftService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBusinessProcessEnvironment;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDraftEditorTest extends TestCase
{
    use BuildsBusinessProcessEnvironment;
    use InteractsWithBusinessDatabases;

    protected array $businessDatabaseTransactionExclusions = [
        'test_audit_failure_rolls_back_revision_operation_pointer_and_version',
        'test_item_or_snapshot_insert_failure_and_overflow_leave_no_partial_revision',
        'test_snapshot_summary_and_current_pointer_failures_each_rollback_the_whole_revision',
        'test_two_processes_with_same_version_allow_exactly_one_revision',
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

    public function test_creation_builds_revision_one_with_exact_lines_totals_and_summaries(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $invoice = $this->createDraft($this->payload($client, $account, $standard, $reduced));
        $revision = $invoice->currentRevision;

        $this->assertSame(1, $invoice->version);
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame('300.0000', $revision->subtotal_before_discount);
        $this->assertSame('30.0000', $revision->discount_total);
        $this->assertSame('270.0000', $revision->tax_base_total);
        $this->assertSame('52.6500', $revision->vat_total);
        $this->assertSame('322.6500', $revision->total_before_rounding);
        $this->assertSame('0.0000', $revision->rounding_adjustment);
        $this->assertSame('322.6500', $revision->grand_total);
        $this->assertCount(2, $revision->items);
        $this->assertCount(2, $revision->vatSummaries);
        $this->assertSame('225.0000', $revision->items[0]->line_net_amount);
        $this->assertSame('47.2500', $revision->items[0]->vat_amount);
        $this->assertSame('fixed', $revision->items[1]->discount_type->value);
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_invoice_discount_is_persisted_allocated_and_revisioned_without_changing_history(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $payload['invoice_discount_type'] = 'fixed';
        $payload['invoice_discount_value'] = '27';
        $invoice = $this->createDraft($payload);
        $first = $invoice->currentRevision;

        $this->assertSame('fixed', $first->invoice_discount_type->value);
        $this->assertSame('27.0000', $first->invoice_discount_value);
        $this->assertSame('27.0000', $first->invoice_discount_amount);
        $this->assertSame('57.0000', $first->discount_total);
        $this->assertSame('22.5000', $first->items[0]->invoice_discount_amount);
        $this->assertSame('4.5000', $first->items[1]->invoice_discount_amount);
        $this->assertSame('243.0000', $first->tax_base_total);
        $this->assertSame('47.3850', $first->vat_total);
        $this->assertSame('290.3900', $first->grand_total);

        $payload['invoice_discount_type'] = 'percentage';
        $payload['invoice_discount_value'] = '20';
        $second = $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);

        $this->assertSame('percentage', $second->invoice_discount_type->value);
        $this->assertSame('54.0000', $second->invoice_discount_amount);
        $this->assertSame('27.0000', $first->fresh()->invoice_discount_amount);
        $this->assertSame(2, $invoice->fresh()->version);
    }

    public function test_cash_czk_draft_rounds_only_final_payable_total_to_whole_crowns(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $payload['payment_method'] = 'cash';
        $revision = $this->createDraft($payload)->currentRevision;

        $this->assertSame('270.0000', $revision->tax_base_total);
        $this->assertSame('52.6500', $revision->vat_total);
        $this->assertSame('322.6500', $revision->total_before_rounding);
        $this->assertSame('0.3500', $revision->rounding_adjustment);
        $this->assertSame('323.0000', $revision->grand_total);
    }

    public function test_real_edits_create_immutable_revisions_and_resnapshot_changed_live_sources(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $first = $invoice->currentRevision;
        $client->forceFill(['display_name' => 'Klient po změně'])->save();
        $payload['note'] = 'Nová poznámka';
        $second = $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);

        $this->assertSame(2, $second->revision_number);
        $this->assertSame('Klient po změně', $second->customerSnapshot->display_name);
        $this->assertSame('Původní klient', $first->customerSnapshot->display_name);
        $this->assertSame($second->id, $invoice->fresh()->current_revision_id);
        $this->assertSame(2, $invoice->fresh()->version);
        $this->assertSame(2, InvoiceRevision::query()->where('invoice_id', $invoice->id)->count());

        $payload['items'][0]['description'] = 'Třetí revize';
        $third = $this->editor()->update($invoice->uuid, 2, (string) Str::uuid(), $payload);
        $this->assertSame(3, $third->revision_number);
        $this->assertSame(3, $invoice->fresh()->version);
    }

    public function test_selected_bank_and_vat_changes_create_new_snapshots_without_changing_history(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $first = $invoice->currentRevision;
        $replacementAccount = new BankAccount;
        $replacementAccount->forceFill([
            'name' => 'Nový účet', 'iban' => 'CZ0708000000001234567899', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => true, 'sort_order' => 1,
        ])->save();
        $zero = $this->rate('Nulová', 'ZERO', 'zero', '0.0000');
        $payload['bank_account_uuid'] = $replacementAccount->uuid;
        $payload['items'][0]['vat_rate_uuid'] = $zero->uuid;
        $second = $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);

        $this->assertSame($replacementAccount->uuid, $second->bankAccountSnapshot->source_bank_account_uuid);
        $this->assertSame('zero', $second->items->first()->vatSnapshot->tax_type->value);
        $this->assertSame($account->uuid, $first->bankAccountSnapshot->source_bank_account_uuid);
        $this->assertSame('standard', $first->items->first()->vatSnapshot->tax_type->value);
        DB::connection('business_1')->table('vat_rates')->where('id', $zero->id)->update(['name' => 'Živá změna']);
        $this->assertSame('Nulová', $second->vatSnapshots->firstWhere('source_vat_rate_uuid', $zero->uuid)->fresh()->name);
    }

    public function test_no_change_save_creates_no_revision_or_audit_and_is_idempotent(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $correlation = (string) Str::uuid();
        $first = $this->editor()->update($invoice->uuid, 1, $correlation, $payload);
        $second = $this->editor()->update($invoice->uuid, 1, $correlation, $payload);

        $this->assertSame($invoice->current_revision_id, $first->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $invoice->fresh()->version);
        $this->assertSame(1, InvoiceRevision::query()->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_draft_operations')->count());
        $this->assertSame(0, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_revision_created')->count());
    }

    public function test_actual_edit_is_idempotent_and_audited_once_without_sensitive_payloads(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $payload['items'][0]['description'] = 'Citlivý popis zakázky';
        $payload['note'] = 'Citlivá interní poznámka';
        $correlation = (string) Str::uuid();
        $created = $this->editor()->update($invoice->uuid, 1, $correlation, $payload);
        $repeated = $this->editor()->update($invoice->uuid, 1, $correlation, $payload);
        $audits = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_revision_created')->get();

        $this->assertSame($created->id, $repeated->id);
        $this->assertSame(2, $invoice->fresh()->version);
        $this->assertCount(1, $audits);
        $auditJson = json_encode($audits->first(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Citlivý popis', $auditJson);
        $this->assertStringNotContainsString('Citlivá interní', $auditJson);
        $this->assertStringNotContainsString($account->iban, $auditJson);
        $this->assertStringContainsString($correlation, $auditJson);
        $this->assertStringContainsString('grand_total', $auditJson);
    }

    public function test_stale_version_conflicts_without_mutating_any_aggregate_data(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $payload['note'] = 'Revize dvě';
        $second = $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);

        try {
            $payload['note'] = 'Přepsání starou verzí';
            $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);
            $this->fail('Neaktuální version měla vyvolat konflikt.');
        } catch (InvoiceDraftVersionConflict) {
            $fresh = $invoice->fresh();
            $this->assertSame(2, $fresh->version);
            $this->assertSame($second->id, $fresh->current_revision_id);
            $this->assertSame(2, InvoiceRevision::query()->count());
            $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_revision_created')->count());
        }
    }

    public function test_same_correlation_for_another_invoice_is_rejected(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $first = $this->createDraft($payload);
        $second = $this->createDraft($payload);
        $correlation = (string) Str::uuid();
        $this->editor()->update($first->uuid, 1, $correlation, $payload);

        $this->expectException(InvoiceDraftIdempotencyConflict::class);
        $this->editor()->update($second->uuid, 1, $correlation, $payload);
    }

    public function test_revision_rows_items_summaries_and_snapshots_are_eloquent_and_database_immutable(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $invoice = $this->createDraft($this->payload($client, $account, $standard, $reduced));
        $revision = $invoice->currentRevision;

        foreach ([$revision, $revision->items->first(), $revision->vatSummaries->first(), $revision->supplierSnapshot] as $model) {
            try {
                $model->forceFill(['updated_at' => now()->addMinute()])->save();
                $this->fail('Historický model neměl jít aktualizovat.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        foreach ([
            ['invoice_items', $revision->items->first()->id, ['description' => 'Přepis']],
            ['invoice_vat_summaries', $revision->vatSummaries->first()->id, ['tax_base' => '0.0000']],
        ] as [$table, $id, $values]) {
            try {
                DB::connection('business_1')->table($table)->where('id', $id)->update($values);
                $this->fail("Trigger tabulky {$table} měl odmítnout update.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(QueryException::class);
        DB::connection('business_1')->table('invoice_revisions')->where('id', $revision->id)->delete();
    }

    public function test_audit_failure_rolls_back_revision_operation_pointer_and_version(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $payload['note'] = 'Změna před chybou auditu';
        Schema::connection('business_1')->drop('audit_logs');

        try {
            $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);
            $this->fail('Selhání auditu mělo rollbacknout editaci.');
        } catch (QueryException) {
            $this->assertSame(1, $invoice->fresh()->version);
            $this->assertSame(1, InvoiceRevision::query()->count());
            $this->assertSame(0, DB::connection('business_1')->table('invoice_draft_operations')->count());
            $this->assertSame('Původní poznámka', $invoice->currentRevision->note);
        }
    }

    public function test_item_or_snapshot_insert_failure_and_overflow_leave_no_partial_revision(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        DB::connection('business_1')->unprepared(
            "CREATE TRIGGER invoice_items_reject_insert BEFORE INSERT ON invoice_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced item failure'",
        );
        $payload['note'] = 'Selhávající revize';

        try {
            $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);
            $this->fail('Vynucené selhání položky mělo přerušit transakci.');
        } catch (QueryException) {
            $this->assertSame(1, InvoiceRevision::query()->count());
            $this->assertSame(1, $invoice->fresh()->version);
            $this->assertSame(2, DB::connection('business_1')->table('invoice_items')->count());
        } finally {
            DB::connection('business_1')->unprepared('DROP TRIGGER IF EXISTS invoice_items_reject_insert');
        }

        $payload['items'][0]['quantity'] = '99999999999999.9999';
        $payload['items'][0]['unit_price'] = '999999999999999.9999';

        try {
            $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);
            $this->fail('Přetečení výpočtu mělo být odmítnuto.');
        } catch (ValidationException) {
            $this->assertSame(1, InvoiceRevision::query()->count());
            $this->assertSame(1, $invoice->fresh()->version);
        }
    }

    public function test_snapshot_summary_and_current_pointer_failures_each_rollback_the_whole_revision(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $payload['note'] = 'Musí rollbacknout';
        $failures = [
            ['invoice_supplier_snapshots_reject_insert', 'invoice_supplier_snapshots', 'BEFORE INSERT'],
            ['invoice_vat_summaries_reject_insert', 'invoice_vat_summaries', 'BEFORE INSERT'],
            ['invoices_reject_update', 'invoices', 'BEFORE UPDATE'],
        ];

        foreach ($failures as [$trigger, $table, $timing]) {
            DB::connection('business_1')->unprepared(
                "CREATE TRIGGER {$trigger} {$timing} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced revision failure'",
            );

            try {
                $this->editor()->update($invoice->uuid, 1, (string) Str::uuid(), $payload);
                $this->fail("Vynucené selhání {$table} mělo přerušit transakci.");
            } catch (QueryException) {
                $this->assertSame(1, InvoiceRevision::query()->count());
                $this->assertSame(1, $invoice->fresh()->version);
                $this->assertSame($invoice->current_revision_id, $invoice->fresh()->current_revision_id);
                $this->assertSame(0, DB::connection('business_1')->table('invoice_draft_operations')->count());
            } finally {
                DB::connection('business_1')->unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    public function test_tenant_isolation_applies_to_invoice_revision_correlation_and_audit(): void
    {
        $sharedInvoiceUuid = (string) Str::uuid();
        $sharedCorrelation = (string) Str::uuid();
        $this->activate(BusinessConnection::Business1);
        [$client1, $account1, $standard1, $reduced1] = $this->sources();
        $payload1 = $this->payload($client1, $account1, $standard1, $reduced1);
        $invoice1 = $this->createDraft($payload1);
        DB::connection('business_1')->table('invoices')->where('id', $invoice1->id)->update(['uuid' => $sharedInvoiceUuid]);
        $invoice1->uuid = $sharedInvoiceUuid;
        $payload1['note'] = 'Business jedna';
        $revision1 = $this->editor()->update($sharedInvoiceUuid, 1, $sharedCorrelation, $payload1);

        $this->activate(BusinessConnection::Business2);
        [$client2, $account2, $standard2, $reduced2] = $this->sources();
        $payload2 = $this->payload($client2, $account2, $standard2, $reduced2);
        $invoice2 = $this->createDraft($payload2);
        DB::connection('business_2')->table('invoices')->where('id', $invoice2->id)->update(['uuid' => $sharedInvoiceUuid]);
        $payload2['note'] = 'Business dvě';
        $revision2 = $this->editor()->update($sharedInvoiceUuid, 1, $sharedCorrelation, $payload2 + ['connection' => 'business_1']);

        $this->assertSame(2, $revision1->revision_number);
        $this->assertSame(2, $revision2->revision_number);
        $this->assertSame(2, DB::connection('business_1')->table('invoice_revisions')->count());
        $this->assertSame(2, DB::connection('business_2')->table('invoice_revisions')->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_draft_operations')->where('correlation_uuid', $sharedCorrelation)->count());
        $this->assertSame(1, DB::connection('business_2')->table('invoice_draft_operations')->where('correlation_uuid', $sharedCorrelation)->count());
        $this->assertFalse(Schema::connection('central')->hasTable('invoice_revisions'));
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_update_request_prohibits_authoritative_technical_fields(): void
    {
        $rules = (new UpdateInvoiceDraftRequest)->rules();

        foreach (['subtotal_before_discount', 'discount_total', 'invoice_discount_amount', 'tax_base_total', 'vat_total', 'total_before_rounding', 'rounding_adjustment', 'grand_total', 'vat_summaries', 'connection', 'business_id', 'status', 'archived_at'] as $field) {
            $this->assertContains('prohibited', $rules[$field]);
        }
        $this->assertContains('required', $rules['version']);
        $this->assertContains('required', $rules['correlation_uuid']);
        $this->assertContains('distinct', $rules['items.*.position']);
    }

    public function test_two_processes_with_same_version_allow_exactly_one_revision(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $standard, $reduced] = $this->sources();
        $payload = $this->payload($client, $account, $standard, $reduced);
        $invoice = $this->createDraft($payload);
        $barrier = storage_path('framework/testing/invoice-edit-'.Str::uuid());
        $processes = [];

        foreach (['Souběžná A', 'Souběžná B'] as $note) {
            $processPayload = $payload;
            $processPayload['note'] = $note;
            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Support/edit-invoice-draft.php'), 'business_1',
                $invoice->uuid, '1', (string) Str::uuid(), base64_encode(json_encode($processPayload, JSON_THROW_ON_ERROR)), $barrier,
            ], base_path(), $this->businessChildProcessEnvironment());
        }

        try {
            foreach ($processes as $process) {
                $process->setTimeout(30);
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
        sort($exitCodes);
        $this->assertSame([0, 20], $exitCodes, implode("\n", array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
        $this->assertSame(2, $invoice->fresh()->version);
        $this->assertSame(2, InvoiceRevision::query()->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_revision_created')->count());
    }

    private function activate(BusinessConnection $connection): Business
    {
        $business = Business::query()->firstOrCreate(['connection_name' => $connection->connectionName()], [
            'uuid' => (string) Str::uuid(), 'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'is_active' => true, 'sort_order' => 1,
        ]);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    /** @return array{Client, BankAccount, VatRate, VatRate} */
    private function sources(): array
    {
        $company = new CompanySetting;
        $company->forceFill([
            'singleton_key' => '1', 'legal_name' => 'Dodavatel s.r.o.', 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'street' => 'Dodavatelská', 'house_number' => '10',
            'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ', 'email' => 'dodavatel@example.test',
            'default_currency' => 'CZK', 'document_locale' => 'cs', 'timezone' => 'Europe/Prague',
            'is_vat_payer' => true, 'vat_registered_on' => '2020-01-01', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'invoice_intro' => 'Úvod', 'invoice_outro' => 'Konec',
        ])->save();
        $client = new Client;
        $client->forceFill([
            'type' => 'company', 'display_name' => 'Původní klient', 'company_name' => 'Klient s.r.o.',
            'registration_number' => '87654321', 'street' => 'Původní', 'house_number' => '1',
            'city' => 'Brno', 'postal_code' => '60200', 'country_code' => 'CZ',
            'email' => 'klient@example.test', 'default_currency' => 'CZK', 'is_active' => true,
        ])->save();
        $account = new BankAccount;
        $account->forceFill([
            'name' => 'Hlavní účet', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => true, 'sort_order' => 0,
        ])->save();
        $standard = $this->rate('Základní', 'STD', 'standard', '21.0000');
        $reduced = $this->rate('Snížená', 'RED', 'reduced', '12.0000');

        return [$client, $account, $standard, $reduced];
    }

    private function rate(string $name, string $code, string $type, ?string $percentage): VatRate
    {
        $rate = new VatRate;
        $rate->forceFill([
            'name' => $name, 'code' => $code, 'tax_type' => $type, 'percentage' => $percentage,
            'valid_from' => '2026-01-01', 'is_active' => true, 'sort_order' => 0,
        ])->save();

        return $rate;
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, BankAccount $account, VatRate $standard, VatRate $reduced): array
    {
        return [
            'customer_uuid' => $client->uuid, 'bank_account_uuid' => $account->uuid, 'currency' => 'CZK',
            'issued_on' => '2026-08-01', 'taxable_supply_on' => '2026-08-01', 'due_on' => '2026-08-15',
            'payment_method' => 'bank_transfer', 'variable_symbol' => '20260001', 'note' => 'Původní poznámka',
            'items' => [
                ['position' => 1, 'description' => 'Služba', 'quantity' => '2.5', 'unit' => 'hod', 'unit_price' => '100', 'discount_type' => 'percentage', 'discount_value' => '10', 'vat_rate_uuid' => $standard->uuid],
                ['position' => 2, 'description' => 'Materiál', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '50', 'discount_type' => 'fixed', 'discount_value' => '5', 'vat_rate_uuid' => $reduced->uuid],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function createDraft(array $payload): Invoice
    {
        return app(InvoiceDraftService::class)->create($payload);
    }

    private function editor(): InvoiceDraftEditor
    {
        return app(InvoiceDraftEditor::class);
    }
}
