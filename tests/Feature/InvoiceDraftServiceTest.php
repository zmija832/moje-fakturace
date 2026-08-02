<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\Exceptions\MissingBusinessContext;
use App\Enums\BusinessConnection;
use App\Http\Requests\StoreInvoiceDraftRequest;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceBankAccountSnapshot;
use App\Models\Business\InvoiceCustomerSnapshot;
use App\Models\Business\InvoiceDraftOperation;
use App\Models\Business\InvoiceItem;
use App\Models\Business\InvoiceRevision;
use App\Models\Business\InvoiceSupplierSnapshot;
use App\Models\Business\InvoiceVatSnapshot;
use App\Models\Business\InvoiceVatSummary;
use App\Models\Business\VatRate;
use App\Models\User;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\VatRateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDraftServiceTest extends TestCase
{
    use InteractsWithBusinessDatabases;

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

    public function test_all_invoice_models_fail_closed_without_context(): void
    {
        app(ActiveBusinessContext::class)->clear();
        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();

        foreach ([
            Invoice::class, InvoiceItem::class, InvoiceSupplierSnapshot::class,
            InvoiceCustomerSnapshot::class, InvoiceBankAccountSnapshot::class,
            InvoiceVatSnapshot::class, InvoiceRevision::class, InvoiceVatSummary::class,
            InvoiceDraftOperation::class,
        ] as $model) {
            try {
                $model::query()->count();
                $this->fail("{$model} měl bez contextu selhat.");
            } catch (MissingBusinessContext) {
                $this->assertSame([], DB::connection('central')->getQueryLog());
            }
        }
    }

    public function test_draft_captures_complete_exact_snapshots_and_items(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $invoice = $this->service()->create($this->payload($client, $account, $rate));

        $this->assertSame('issued_invoice', $invoice->document_type->value);
        $this->assertSame('draft', $invoice->status->value);
        $this->assertSame(1, $invoice->version);
        $this->assertSame(1, $invoice->currentRevision->revision_number);
        $this->assertSame('Dodavatel s.r.o.', $invoice->supplierSnapshot->legal_name);
        $this->assertSame('Původní klient', $invoice->customerSnapshot->display_name);
        $this->assertSame('CZ6508000000192000145399', $invoice->bankAccountSnapshot->iban);
        $this->assertSame('out_of_scope', $invoice->vatSnapshots->sole()->tax_type->value);
        $this->assertNull($invoice->vatSnapshots->sole()->percentage);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('2.5000', $invoice->items[0]->quantity);
        $this->assertSame('1250.5000', $invoice->items[0]->unit_price);
        $this->assertSame($invoice->vatSnapshots->sole()->id, $invoice->items[0]->invoice_vat_snapshot_id);
        $this->assertSame('3226.2500', $invoice->currentRevision->tax_base_total);
        $this->assertSame('3226.2500', $invoice->currentRevision->grand_total);
        $this->assertSame('central', DB::getDefaultConnection());
    }

    public function test_snapshots_do_not_change_with_live_sources_and_are_database_immutable(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $invoice = $this->service()->create($this->payload($client, $account, $rate));

        CompanySetting::query()->where('singleton_key', '1')->update(['legal_name' => 'Nový dodavatel']);
        $client->forceFill(['display_name' => 'Nový klient', 'street' => 'Nová 99'])->save();
        $account->forceFill(['iban' => 'CZ0708000000001234567899'])->save();
        DB::connection('business_1')->table('vat_rates')->where('id', $rate->id)->update(['name' => 'Nový režim']);

        $invoice->refresh()->load(['supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'vatSnapshots']);
        $this->assertSame('Dodavatel s.r.o.', $invoice->supplierSnapshot->legal_name);
        $this->assertSame('Původní klient', $invoice->customerSnapshot->display_name);
        $this->assertSame('Původní', $invoice->customerSnapshot->street);
        $this->assertSame('1', $invoice->customerSnapshot->house_number);
        $this->assertSame('CZ6508000000192000145399', $invoice->bankAccountSnapshot->iban);
        $this->assertSame('Mimo DPH', $invoice->vatSnapshots->sole()->name);

        app(VatRateService::class)->update($rate->uuid, [
            'name' => 'Změna živé sazby draftu', 'code' => 'OUT', 'tax_type' => 'out_of_scope',
            'percentage' => null, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $this->assertSame('Mimo DPH', $invoice->vatSnapshots->sole()->fresh()->name);

        try {
            $invoice->supplierSnapshot->forceFill(['legal_name' => 'Přepis'])->save();
            $this->fail('Snapshot neměl jít změnit přes Eloquent.');
        } catch (LogicException) {
            $this->assertSame('Dodavatel s.r.o.', $invoice->supplierSnapshot->fresh()->legal_name);
        }

        $this->expectException(QueryException::class);
        DB::connection('business_1')->table('invoice_customer_snapshots')
            ->where('invoice_revision_id', $invoice->current_revision_id)->update(['display_name' => 'Přímý přepis']);
    }

    public function test_invoice_data_and_same_source_uuids_are_physically_isolated(): void
    {
        $sharedClientUuid = (string) Str::uuid();
        $this->activate(BusinessConnection::Business1);
        [$firstClient, $firstAccount, $firstRate] = $this->sources($sharedClientUuid);
        $first = $this->service()->create($this->payload($firstClient, $firstAccount, $firstRate));

        $this->activate(BusinessConnection::Business2);
        [$secondClient, $secondAccount, $secondRate] = $this->sources($sharedClientUuid, 'Druhý klient');
        $second = $this->service()->create($this->payload($secondClient, $secondAccount, $secondRate));

        $this->assertSame(1, DB::connection('business_1')->table('invoices')->count());
        $this->assertSame(1, DB::connection('business_2')->table('invoices')->count());
        $this->assertSame('Původní klient', DB::connection('business_1')->table('invoice_customer_snapshots')->value('display_name'));
        $this->assertSame('Druhý klient', DB::connection('business_2')->table('invoice_customer_snapshots')->value('display_name'));
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertFalse(Schema::connection('central')->hasTable('invoices'));
    }

    public function test_request_technical_fields_are_prohibited_and_service_ignores_connection(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $request = new StoreInvoiceDraftRequest;

        foreach (['id', 'uuid', 'status', 'document_type', 'document_number', 'connection', 'business_id', 'supplier_snapshot', 'customer_snapshot', 'bank_account_snapshot', 'vat_snapshot', 'version', 'grand_total', 'vat_summaries'] as $field) {
            $this->assertContains('prohibited', $request->rules()[$field]);
        }

        $invoice = $this->service()->create($this->payload($client, $account, $rate) + [
            'connection' => 'business_2', 'status' => 'issued', 'uuid' => (string) Str::uuid(),
        ]);
        $this->assertSame('business_1', $invoice->getConnectionName());
        $this->assertSame(0, DB::connection('business_2')->table('invoices')->count());
        $this->assertSame('draft', $invoice->status->value);
    }

    public function test_inactive_foreign_sources_and_wrong_bank_currency_are_rejected(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $account->forceFill(['currency' => 'EUR'])->save();

        try {
            $this->service()->create($this->payload($client, $account, $rate));
            $this->fail('Účet v jiné měně měl být odmítnut.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_account_uuid', $exception->errors());
            $this->assertSame(0, Invoice::query()->count());
        }

        $client->forceFill(['is_active' => false])->save();
        $this->expectException(ModelNotFoundException::class);
        $this->service()->create($this->payload($client, null, $rate));
    }

    public function test_audit_is_safe_tenant_local_and_failure_rolls_back_every_table(): void
    {
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        $invoice = $this->service()->create($this->payload($client, $account, $rate));
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_created')->first();

        $this->assertSame($invoice->uuid, $audit->auditable_uuid);
        $this->assertStringNotContainsString('Původní klient', (string) $audit->new_values);
        $this->assertStringNotContainsString('CZ6508000000192000145399', (string) $audit->new_values);
        $this->assertSame(0, DB::connection('business_2')->table('audit_logs')->count());

        $this->refreshBusinessTestDatabases();
        $this->activate(BusinessConnection::Business1);
        [$client, $account, $rate] = $this->sources();
        Schema::connection('business_1')->drop('audit_logs');

        try {
            $this->service()->create($this->payload($client, $account, $rate));
            $this->fail('Selhání auditu mělo rollbacknout fakturu.');
        } catch (QueryException) {
            foreach (['invoices', 'invoice_revisions', 'invoice_items', 'invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots', 'invoice_vat_snapshots', 'invoice_vat_summaries'] as $table) {
                $this->assertSame(0, DB::connection('business_1')->table($table)->count());
            }
        }
    }

    public function test_policy_uses_existing_business_roles(): void
    {
        $business = $this->activate(BusinessConnection::Business1);
        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $admin->businesses()->attach($business, ['role' => 'admin']);
        $viewer->businesses()->attach($business, ['role' => 'viewer']);

        $this->actingAs($admin);
        $this->assertTrue(Gate::allows('create', Invoice::class));
        $this->assertTrue(Gate::allows('updateAny', Invoice::class));
        $this->actingAs($viewer);
        $this->assertTrue(Gate::allows('viewAny', Invoice::class));
        $this->assertFalse(Gate::allows('create', Invoice::class));
        $this->assertFalse(Gate::allows('updateAny', Invoice::class));
    }

    private function activate(BusinessConnection $connection): Business
    {
        $business = Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => $connection->connectionName(),
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection->connectionName(), 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true, 'sort_order' => 1,
        ]);
        app(ActiveBusinessContext::class)->set($business);

        return $business;
    }

    /** @return array{Client, BankAccount, VatRate} */
    private function sources(?string $clientUuid = null, string $clientName = 'Původní klient'): array
    {
        $company = new CompanySetting;
        $company->forceFill([
            'singleton_key' => '1', 'legal_name' => 'Dodavatel s.r.o.', 'registration_number' => '12345678',
            'tax_id' => 'CZ12345678', 'vat_id' => null, 'street' => 'Dodavatelská', 'house_number' => '10',
            'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ', 'email' => 'dodavatel@example.test',
            'default_currency' => 'CZK', 'document_locale' => 'cs', 'timezone' => 'Europe/Prague',
            'is_vat_payer' => false, 'default_due_days' => 14, 'default_payment_method' => 'bank_transfer',
            'invoice_intro' => 'Úvod', 'invoice_outro' => 'Konec',
        ])->save();

        $client = new Client;
        $client->forceFill([
            'uuid' => $clientUuid, 'type' => 'company', 'display_name' => $clientName,
            'company_name' => 'Klient s.r.o.', 'registration_number' => '87654321',
            'street' => 'Původní', 'house_number' => '1', 'city' => 'Brno', 'postal_code' => '60200',
            'country_code' => 'CZ', 'email' => 'klient@example.test', 'default_currency' => 'CZK',
            'is_active' => true,
        ])->save();

        $account = new BankAccount;
        $account->forceFill([
            'name' => 'Hlavní účet', 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX',
            'currency' => 'CZK', 'is_active' => true, 'sort_order' => 0,
        ])->save();

        $rate = new VatRate;
        $rate->forceFill([
            'name' => 'Mimo DPH', 'code' => 'OUT', 'tax_type' => 'out_of_scope', 'percentage' => null,
            'valid_from' => '2026-01-01', 'valid_to' => null, 'is_active' => true, 'sort_order' => 0,
        ])->save();

        return [$client, $account, $rate];
    }

    private function service(): InvoiceDraftService
    {
        return app(InvoiceDraftService::class);
    }

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
            'note' => 'Interní poznámka',
            'items' => [
                ['description' => 'Služba', 'quantity' => '2,5', 'unit' => 'hod', 'unit_price' => '1250,5', 'vat_rate_uuid' => $rate->uuid],
                ['description' => 'Materiál', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '100', 'vat_rate_uuid' => $rate->uuid],
            ],
        ];
    }
}
