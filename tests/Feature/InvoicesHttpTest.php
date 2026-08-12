<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business;
use App\Models\Business\BankAccount;
use App\Models\Business\BankAccountDefault;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentSequence;
use App\Models\Business\DocumentSequenceDefault;
use App\Models\Business\Invoice;
use App\Models\Business\VatRate;
use App\Models\Business\VatRateDefault;
use App\Models\User;
use App\Services\Business\InvoiceDraftService;
use App\Services\Business\InvoiceIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicesHttpTest extends TestCase
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

    public function test_list_is_authorized_filterable_paginated_and_tenant_safe(): void
    {
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$client, $account, $rate] = $this->sources('Alfa odběratel');
        $draft = app(InvoiceDraftService::class)->create($this->payload($client, $account, $rate));
        $this->sequence(default: true);
        $issued = app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid());

        $other = $this->business(BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($other);
        [$foreignClient, $foreignAccount, $foreignRate] = $this->sources('Tajný cizí klient');
        app(InvoiceDraftService::class)->create($this->payload($foreignClient, $foreignAccount, $foreignRate));
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->businessSession($business));
        DB::connection('business_1')->flushQueryLog();
        DB::connection('business_1')->enableQueryLog();
        $this->get(route('invoices.index'))->assertOk()->assertSee($issued->document_number)
            ->assertSee('Alfa odběratel')->assertDontSee('Tajný cizí klient')->assertSee('lg:hidden', false);
        $this->assertLessThanOrEqual(10, count(DB::connection('business_1')->getQueryLog()));
        DB::connection('business_1')->disableQueryLog();

        app(ActiveBusinessContext::class)->set($business);
        foreach (range(1, 21) as $pageItem) {
            app(InvoiceDraftService::class)->create($this->payload($client, $account, $rate, [
                'variable_symbol' => sprintf('2026%04d', $pageItem),
                'note' => 'Stránkovaný návrh '.$pageItem,
            ]));
        }
        app(ActiveBusinessContext::class)->clear();
        $this->get(route('invoices.index'))->assertOk()->assertSee('page=2', false);
        $this->get(route('invoices.index', ['page' => 2]))->assertOk()->assertSee($issued->document_number);
        $this->get(route('invoices.index', ['status' => 'issued', 'currency' => 'CZK', 'search' => 'Alfa']))
            ->assertOk()->assertSee($issued->document_number)->assertSee('Alfa odběratel');
        $this->get(route('invoices.index', ['status' => 'draft']))->assertOk()->assertDontSee($issued->document_number);
        $this->get(route('invoices.index', ['sort' => 'DROP TABLE invoices']))->assertSessionHasErrors('sort');
        $this->get(route('invoices.index', ['connection' => 'business_2']))->assertSessionHasErrors('connection');

        [$viewer] = $this->membership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->businessSession($business));
        $this->get(route('invoices.index'))->assertOk()->assertDontSee('Nová faktura');
        $this->get(route('invoices.show', $issued->uuid))->assertOk()->assertDontSee('Upravit návrh');
        $this->get(route('invoices.create'))->assertForbidden();
    }

    public function test_create_preview_and_edit_use_services_and_reject_technical_fields(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$client, $account, $rate] = $this->sources();
        $this->defaults($account, $rate);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $this->get(route('invoices.create'))->assertOk()->assertSee('Nová faktura')
            ->assertSee('name="_token"', false)->assertSee('Finální částky vždy znovu vypočítá server')
            ->assertSee('aria-label="Vytvořit nového klienta"', false)
            ->assertSee('Načíst z ARES')
            ->assertSee('name="items[0][vat_rate_uuid]"', false)
            ->assertSee('Cena položky')
            ->assertSee('previewLineTotal(index+1)', false)
            ->assertSee('Posunout položku ${index+1} nahoru', false)
            ->assertSee('name="country_code"', false)->assertDontSee('name="is_active"', false)
            ->assertDontSee('business_id')->assertDontSee('business_1');
        $before = $this->counts();
        $this->postJson(route('invoices.preview'), $this->payload($client, $account, $rate))
            ->assertOk()->assertJsonPath('totals.grand_total', '100.0000');
        $this->assertSame($before, $this->counts());

        $response = $this->post(route('invoices.store'), $this->payload($client, $account, $rate));
        $invoice = Invoice::query()->firstOrFail();
        $response->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status', 'Návrh faktury byl vytvořen.');
        $this->assertSame(1, $invoice->currentRevision->revision_number);
        $this->assertSame('100.0000', $invoice->currentRevision->grand_total);
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('Sazba DPH')->assertSee($rate->name);
        $this->get(route('invoices.edit', $invoice->uuid))->assertOk()
            ->assertDontSee('aria-label="Vytvořit nového klienta"', false)
            ->assertDontSee('Načíst z ARES')
            ->assertDontSee('Nový klient');

        $this->post(route('invoices.store'), $this->payload($client, $account, $rate) + ['grand_total' => '0', 'connection' => 'business_2'])
            ->assertSessionHasErrors(['grand_total', 'connection']);
        $correlation = (string) Str::uuid();
        $changed = $this->payload($client, $account, $rate, ['note' => 'Nová bezpečná poznámka', 'version' => 1, 'correlation_uuid' => $correlation]);
        $this->put(route('invoices.update', $invoice->uuid), $changed)->assertSessionHas('status', 'Návrh faktury byl aktualizován.');
        $this->assertSame(2, $invoice->fresh()->version);
        $this->put(route('invoices.update', $invoice->uuid), $changed)->assertSessionHas('status', 'Nebyly zjištěny žádné změny.');
        $this->assertSame(2, $invoice->fresh()->version);
        $stale = $this->payload($client, $account, $rate, ['note' => 'Přepsat', 'version' => 1, 'correlation_uuid' => (string) Str::uuid()]);
        $this->put(route('invoices.update', $invoice->uuid), $stale)->assertSessionHas('error');
        $this->assertSame('Nová bezpečná poznámka', $invoice->fresh()->currentRevision->note);
    }

    public function test_quick_created_client_can_be_used_immediately_for_invoice_snapshot(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [, $account, $rate] = $this->sources();
        $this->defaults($account, $rate);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $quickClient = $this->postJson(route('clients.store'), [
            'type' => 'company', 'company_name' => 'Rychlý odběratel s.r.o.',
            'registration_number' => '00123456', 'tax_id' => 'CZ00123456',
            'phone' => '+420 111 222 333', 'email' => 'rychly@example.test',
            'street' => 'Rychlá 10', 'city' => 'Brno', 'postal_code' => '60200', 'country_code' => 'CZ',
        ])->assertCreated()->json('client');

        $client = Client::query()->where('uuid', $quickClient['uuid'])->firstOrFail();
        $this->post(route('invoices.store'), $this->payload($client, $account, $rate))->assertSessionHasNoErrors();

        $snapshot = Invoice::query()->firstOrFail()->currentRevision->customerSnapshot;
        $this->assertSame($client->uuid, $snapshot->source_client_uuid);
        $this->assertSame('Rychlý odběratel s.r.o.', $snapshot->display_name);
        $this->assertSame('Rychlá 10', $snapshot->street);
        $this->assertSame('Brno', $snapshot->city);
        $this->assertSame('60200', $snapshot->postal_code);
        $this->assertSame('CZ', $snapshot->country_code);
    }

    public function test_non_payer_create_preview_and_edit_resolve_vat_only_on_server(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$client, $account, $rate] = $this->sources();
        CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)
            ->update(['is_vat_payer' => false, 'vat_id' => null]);
        $nonPayerRate = VatRate::query()->where('tax_type', 'non_payer')->sole();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $create = $this->get(route('invoices.create'))->assertOk();
        $create->assertDontSee('name="items[0][vat_rate_uuid]"', false)
            ->assertDontSee('id="ns-vat"', false)
            ->assertDontSee('Pro zvolené DUZP není nastavena výchozí sazba DPH. Vyberte ji ručně.');

        $payload = $this->payloadWithoutVat($client, $account, $rate);
        $preview = $this->postJson(route('invoices.preview'), $payload)->assertOk();
        $preview->assertJsonPath('summaries.0.tax_type', 'non_payer')
            ->assertJsonPath('summaries.0.vat_amount', '0.0000')
            ->assertJsonPath('totals.grand_total', '100.0000');

        $this->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
        $invoice = Invoice::query()->firstOrFail();
        $firstRevision = $invoice->currentRevision;
        $this->assertSame($nonPayerRate->uuid, $firstRevision->vatSnapshots->sole()->source_vat_rate_uuid);
        $this->assertSame('non_payer', $firstRevision->vatSnapshots->sole()->tax_type->value);
        $this->assertNull($firstRevision->vatSnapshots->sole()->percentage);
        $this->assertSame('0.0000', $firstRevision->vat_total);

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('Neplátce DPH')
            ->assertSee('Výsledná částka')
            ->assertDontSee('Po slevě')
            ->assertDontSee('Sazba DPH');

        $edit = $this->get(route('invoices.edit', $invoice->uuid))->assertOk();
        $edit->assertDontSee('name="items[0][vat_rate_uuid]"', false)
            ->assertDontSee('id="ns-vat"', false)
            ->assertDontSee('Pro zvolené DUZP není nastavena výchozí sazba DPH. Vyberte ji ručně.');

        $updated = $payload;
        $updated['note'] = 'Nová revize neplátce';
        $updated['version'] = 1;
        $updated['correlation_uuid'] = (string) Str::uuid();
        $this->put(route('invoices.update', $invoice->uuid), $updated)->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(2, $invoice->version);
        $this->assertSame(2, $invoice->revisions()->count());
        $this->assertSame($nonPayerRate->uuid, $invoice->currentRevision->vatSnapshots->sole()->source_vat_rate_uuid);
        $this->assertSame($nonPayerRate->uuid, $firstRevision->vatSnapshots->sole()->fresh()->source_vat_rate_uuid);
        $this->assertSame('non_payer', $invoice->currentRevision->vatSnapshots->sole()->tax_type->value);

        $forged = $payload;
        $forged['items'][0]['vat_rate_uuid'] = $rate->uuid;
        $forgedResponse = $this->post(route('invoices.store'), $forged);
        $this->assertSame(422, $forgedResponse->getStatusCode(), $forgedResponse->getContent());
        $forgedResponse->assertJsonValidationErrors('items.0.vat_rate_uuid');
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_vat_payer_keeps_required_select_and_missing_default_warning(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        $this->sources();
        $systemRateUuid = VatRate::query()->where('tax_type', 'non_payer')->value('uuid');
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $response = $this->get(route('invoices.create'))->assertOk()
            ->assertSee('name="items[0][vat_rate_uuid]"', false)
            ->assertSee('id="ns-vat"', false)
            ->assertSee('Pro zvolené DUZP není nastavena výchozí sazba DPH. Vyberte ji ručně.');
        $this->assertStringNotContainsString($systemRateUuid, $response->getContent());
    }

    public function test_create_form_maps_validation_errors_to_static_and_dynamic_fields(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$client, $account, $rate] = $this->sources();
        $this->defaults($account, $rate);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $payload = $this->payload($client, $account, $rate, [
            'customer_uuid' => 'invalid-client',
            'bank_account_uuid' => 'invalid-account',
            'currency' => 'INVALID',
            'issued_on' => 'invalid-date',
            'items' => [
                ['position' => 1, 'description' => '', 'quantity' => 'invalid', 'unit' => str_repeat('x', 33), 'unit_price' => 'invalid', 'discount_type' => 'invalid', 'discount_value' => 'invalid', 'vat_rate_uuid' => 'invalid-vat'],
                ['position' => 2, 'description' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '10', 'discount_type' => 'none', 'discount_value' => '0', 'vat_rate_uuid' => ''],
            ],
        ]);

        $this->from(route('invoices.create'))->post(route('invoices.store'), $payload)
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors([
                'customer_uuid', 'bank_account_uuid', 'currency', 'issued_on',
                'items.0.description', 'items.0.quantity', 'items.0.unit',
                'items.0.unit_price', 'items.0.discount_type', 'items.0.discount_value',
                'items.0.vat_rate_uuid', 'items.1.description', 'items.1.vat_rate_uuid',
            ]);

        $response = $this->followingRedirects()->from(route('invoices.create'))
            ->post(route('invoices.store'), $payload)->assertOk();
        $response->assertSee('Formulář se nepodařilo uložit.')
            ->assertSee('href="#customer_uuid"', false)
            ->assertSee('href="#bank_account_uuid"', false)
            ->assertSee('href="#currency"', false)
            ->assertSee('href="#issued_on"', false)
            ->assertSee('href="#item-0-quantity"', false)
            ->assertSee('href="#item-0-unit"', false)
            ->assertSee('href="#item-0-price"', false)
            ->assertSee('href="#item-0-discount-type"', false)
            ->assertSee('href="#item-0-discount-value"', false)
            ->assertSee('href="#item-0-vat"', false)
            ->assertSee('href="#item-1-description"', false)
            ->assertSee('href="#item-1-vat"', false)
            ->assertSee('id="customer_uuid" name="customer_uuid" required', false)
            ->assertSee('aria-invalid="true" aria-describedby="customer_uuid-error"', false)
            ->assertSee(':aria-invalid="hasFieldError(index,\'description\') ? \'true\' : null"', false)
            ->assertSee(':aria-invalid="hasFieldError(index,\'vat_rate_uuid\') ? \'true\' : null"', false)
            ->assertSee('focusErrorField', false)
            ->assertSee('items.1.description', false);
    }

    public function test_issue_ui_is_idempotent_and_issued_detail_uses_only_snapshots(): void
    {
        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$client, $account, $rate] = $this->sources('Historický klient');
        $sequence = $this->sequence(default: true);
        $invoice = app(InvoiceDraftService::class)->create($this->payload($client, $account, $rate));
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('Pokračovat k nevratnému vystavení')
            ->assertSee('další číslo přibližně')->assertSee('Upravit návrh');
        $correlation = (string) Str::uuid();
        $payload = ['expected_version' => 1, 'correlation_uuid' => $correlation, 'document_sequence_uuid' => $sequence->uuid];
        $this->post(route('invoices.issue', $invoice->uuid), $payload)->assertSessionHas('status');
        $issued = $invoice->fresh();
        $this->assertSame('issued', $issued->status->value);
        $this->assertNotNull($issued->document_number);
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->post(route('invoices.issue', $invoice->uuid), $payload)->assertForbidden();
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());

        app(ActiveBusinessContext::class)->set($business);
        $client->forceFill(['display_name' => 'Změněný živý klient'])->save();
        $account->forceFill(['name' => 'Změněný živý účet'])->save();
        $rate->forceFill(['name' => 'Změněná živá sazba'])->save();
        CompanySetting::query()->update(['legal_name' => 'Změněná živá firma']);
        app(ActiveBusinessContext::class)->clear();
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee($issued->document_number)
            ->assertSee('Historický klient')->assertSee('Vystavený doklad je neměnný')
            ->assertDontSee('Změněný živý klient')->assertDontSee('Změněný živý účet')
            ->assertDontSee('Změněná živá sazba')->assertDontSee('Změněná živá firma')
            ->assertDontSee('Upravit návrh')->assertDontSee('document_number_allocation_id');
        $this->get(route('invoices.edit', $invoice->uuid))->assertForbidden();
    }

    public function test_routes_options_policy_and_html_security(): void
    {
        foreach (['invoices.index', 'invoices.create', 'invoices.store', 'invoices.preview', 'invoices.show', 'invoices.edit', 'invoices.update', 'invoices.issue'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('business.context', $middleware);
            $this->assertContains('business.required', $middleware);
        }
        $this->assertNull(app('router')->getRoutes()->getByName('invoices.destroy'));

        [$admin, $business] = $this->membership('admin', BusinessConnection::Business1);
        app(ActiveBusinessContext::class)->set($business);
        [$active, $account, $rate] = $this->sources();
        $inactive = $this->client('Neaktivní klient', false);
        $archived = $this->client('Archivovaný klient', true, now());
        $wrongAccount = $this->account('EUR účet', 'EUR');
        $this->defaults($account, $rate);
        $wrongSequence = $this->sequence(documentType: 'advance_invoice');
        $inactiveSequence = $this->sequence(active: false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->businessSession($business));
        $html = $this->get(route('invoices.create'))->assertOk()->assertSee($active->display_name)
            ->assertDontSee($inactive->display_name)->assertDontSee($archived->display_name)
            ->assertSee($account->name)->assertSee($wrongAccount->name)->assertSee($rate->name)
            ->assertDontSee($wrongSequence->name)->assertDontSee($inactiveSequence->name);
        $html->assertDontSee('business_id')->assertDontSee('business_1')->assertDontSee('allocation_id')
            ->assertDontSee('name="grand_total"', false)->assertDontSee('name="vat_summaries"', false);
    }

    /** @return array{User,Business} */
    private function membership(string $role, BusinessConnection $connection, ?Business $business = null): array
    {
        $user = User::factory()->create();
        $business ??= $this->business($connection);
        $user->businesses()->attach($business, ['role' => $role]);

        return [$user, $business];
    }

    private function business(BusinessConnection $connection): Business
    {
        return Business::query()->create([
            'uuid' => (string) Str::uuid(), 'display_name' => $connection === BusinessConnection::Business1 ? 'První subjekt' : 'Druhý subjekt',
            'registration_number' => $connection === BusinessConnection::Business1 ? '12345678' : '87654321',
            'short_label' => $connection === BusinessConnection::Business1 ? 'S1' : 'S2', 'visual_identifier' => 'briefcase',
            'connection_name' => $connection->connectionName(), 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    /** @return array<string,string> */
    private function businessSession(Business $business): array
    {
        return [config('business.session_key') => $business->uuid];
    }

    /** @return array{Client,BankAccount,VatRate} */
    private function sources(string $clientName = 'Testovací klient'): array
    {
        $company = new CompanySetting;
        $company->forceFill([
            'singleton_key' => '1', 'legal_name' => 'Dodavatel s.r.o.', 'registration_number' => '12345678',
            'street' => 'Dodavatelská', 'house_number' => '10', 'city' => 'Praha', 'postal_code' => '11000',
            'country_code' => 'CZ', 'email' => 'dodavatel@example.test', 'default_currency' => 'CZK',
            'document_locale' => 'cs', 'timezone' => 'Europe/Prague', 'is_vat_payer' => true,
            'vat_id' => 'CZ12345678',
            'default_due_days' => 14, 'default_payment_method' => 'bank_transfer',
        ])->save();
        $client = $this->client($clientName);
        $account = $this->account('Hlavní účet', 'CZK');
        $rate = new VatRate;
        $rate->forceFill([
            'name' => 'Mimo DPH', 'code' => 'OUT', 'tax_type' => 'out_of_scope', 'percentage' => null,
            'valid_from' => '2026-01-01', 'valid_to' => null, 'is_active' => true, 'sort_order' => 0,
        ])->save();

        return [$client, $account, $rate];
    }

    private function client(string $name, bool $active = true, mixed $archivedAt = null): Client
    {
        $client = new Client;
        $client->forceFill([
            'type' => 'company', 'display_name' => $name, 'company_name' => $name.' s.r.o.',
            'registration_number' => '87654321', 'street' => 'Klientská', 'house_number' => '1',
            'city' => 'Brno', 'postal_code' => '60200', 'country_code' => 'CZ',
            'default_currency' => 'CZK', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'is_active' => $active, 'archived_at' => $archivedAt,
        ])->save();

        return $client;
    }

    private function account(string $name, string $currency): BankAccount
    {
        $account = new BankAccount;
        $account->forceFill(['name' => $name, 'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX', 'currency' => $currency, 'is_active' => true, 'sort_order' => 0])->save();

        return $account;
    }

    private function sequence(bool $default = false, string $documentType = 'issued_invoice', bool $active = true): DocumentSequence
    {
        $sequence = new DocumentSequence;
        $sequence->forceFill([
            'document_type' => $documentType, 'name' => $documentType.' řada', 'prefix' => 'FV-',
            'suffix' => '', 'year_format' => 'yyyy', 'sequence_digits' => 5, 'start_number' => 1,
            'next_number' => 1, 'reset_period' => 'yearly', 'current_period' => null,
            'is_active' => $active, 'sort_order' => 0,
        ])->save();
        if ($default) {
            $assignment = new DocumentSequenceDefault;
            $assignment->forceFill(['document_type' => $documentType, 'document_sequence_id' => $sequence->id])->save();
        }

        return $sequence;
    }

    private function defaults(BankAccount $account, VatRate $rate): void
    {
        $bankDefault = new BankAccountDefault;
        $bankDefault->forceFill(['currency' => 'CZK', 'bank_account_id' => $account->id])->save();
        $vatDefault = new VatRateDefault;
        $vatDefault->forceFill(['context' => 'sales', 'vat_rate_id' => $rate->id])->save();
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function payload(Client $client, BankAccount $account, VatRate $rate, array $overrides = []): array
    {
        return array_replace([
            'customer_uuid' => $client->uuid, 'bank_account_uuid' => $account->uuid, 'currency' => 'CZK',
            'issued_on' => '2026-08-02', 'taxable_supply_on' => '2026-08-02', 'due_on' => '2026-08-16',
            'payment_method' => 'bank_transfer', 'variable_symbol' => '20260001', 'note' => null,
            'invoice_discount_type' => 'none', 'invoice_discount_value' => '0',
            'items' => [[
                'position' => 1, 'description' => 'Bezpečná služba', 'quantity' => '1', 'unit' => 'ks',
                'unit_price' => '100', 'discount_type' => 'none', 'discount_value' => '0',
                'vat_rate_uuid' => $rate->uuid,
            ]],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function payloadWithoutVat(Client $client, BankAccount $account, VatRate $rate): array
    {
        $payload = $this->payload($client, $account, $rate);

        foreach ($payload['items'] as &$item) {
            unset($item['vat_rate_uuid']);
        }
        unset($item);

        return $payload;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return collect(['invoices', 'invoice_revisions', 'invoice_items', 'invoice_vat_summaries', 'audit_logs'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::connection('business_1')->table($table)->count()])->all();
    }
}
