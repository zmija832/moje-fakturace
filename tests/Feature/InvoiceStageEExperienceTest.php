<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\Client;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceLabel;
use App\Services\Business\InvoiceDuplicator;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoiceLabelService;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceStageEExperienceTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
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

    public function test_duplicate_picker_searches_active_clients_and_uses_selected_client_without_copying_labels(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$source] = $this->createIssuedInvoice();
        $alphabetical = $this->client('Abecední odběratel', '10101010', 'abeceda@example.test');
        $selected = $this->client('Zvolený odběratel', '11223344', 'zvoleny@example.test');
        $inactive = $this->client('Neaktivní odběratel', '99887766', 'inactive@example.test', false);
        $this->client('Archivovaný odběratel', '88776655', 'archived@example.test')->forceFill(['archived_at' => now()])->save();
        $label = app(InvoiceLabelService::class)->create(['name' => 'Priorita', 'color' => 'rose', 'is_active' => true]);
        app(InvoiceLabelService::class)->attach($source->uuid, $label->uuid);
        $foreignBusiness = $this->deliveryMembership('admin', BusinessConnection::Business2)[1];
        app(ActiveBusinessContext::class)->set($foreignBusiness);
        $this->client('Cizí tajný klient', '44332211', 'foreign@example.test');
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.duplicate-form', $source->uuid))->assertOk()
            ->assertSee('Název, jméno, IČO nebo e-mail')
            ->assertSee('Nový klient');
        $this->getJson(route('clients.search', ['q' => '11223344']))->assertOk()
            ->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.uuid', $selected->uuid);
        $this->getJson(route('clients.search', ['q' => 'Zvolený']))->assertOk()
            ->assertJsonPath('clients.0.uuid', $selected->uuid);
        $this->getJson(route('clients.search', ['q' => 'inactive@example.test']))->assertOk()
            ->assertJsonCount(0, 'clients');
        $this->getJson(route('clients.search', ['q' => 'archived@example.test']))->assertOk()
            ->assertJsonCount(0, 'clients');
        $this->getJson(route('clients.search', ['q' => 'foreign@example.test']))->assertOk()
            ->assertJsonCount(0, 'clients');
        $this->getJson(route('clients.search'))->assertOk()
            ->assertJsonPath('clients.0.uuid', $alphabetical->uuid);

        $created = $this->postJson(route('clients.store'), [
            'type' => 'company', 'company_name' => 'Nový klient z duplikace',
            'registration_number' => '55667788', 'email' => 'novy@example.test',
            'street' => 'Nová 1', 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ',
        ])->assertCreated()->json('client');

        $this->post(route('invoices.duplicate', $source->uuid), ['client_uuid' => $created['uuid']])
            ->assertRedirect();
        $duplicate = Invoice::query()->where('uuid', '!=', $source->uuid)->sole();
        $this->assertSame($created['uuid'], $duplicate->currentRevision->customerSnapshot->source_client_uuid);
        $this->assertCount(0, $duplicate->labels);
        $this->assertCount(1, $source->fresh()->labels);
        $this->assertFalse($inactive->is_active);
    }

    public function test_labels_are_tenant_local_audited_filterable_and_absent_from_customer_outputs(): void
    {
        $this->assertTrue(Schema::connection('business_1')->hasColumns('invoice_labels', ['uuid', 'name', 'color', 'is_active']));
        $this->assertTrue(Schema::connection('business_1')->hasColumns('invoice_invoice_label', ['invoice_id', 'invoice_label_id']));
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->post(route('invoice-labels.store'), ['name' => 'K interní kontrole', 'color' => 'amber', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $label = InvoiceLabel::query()->sole();
        $this->put(route('invoice-labels.update', $label->uuid), ['name' => 'K interní kontrole', 'color' => 'violet', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('violet', $label->fresh()->color->value);
        $this->post(route('invoices.labels.attach', $invoice->uuid), ['label_uuid' => $label->uuid])
            ->assertSessionHasNoErrors();
        $index = $this->get(route('invoices.index', ['label_uuid' => $label->uuid, 'status' => 'issued']))->assertOk()
            ->assertSee($invoice->document_number)->assertSee('K interní kontrole');
        $this->assertGreaterThanOrEqual(2, substr_count($index->getContent(), 'K interní kontrole'));
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('K interní kontrole');
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice_label.created'], 'business_1');
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.label_attached'], 'business_1');

        $link = app(InvoicePublicLinkService::class)->create($invoice->fresh());
        $this->get(app(InvoicePublicLinkService::class)->url($link))->assertOk()->assertDontSee('K interní kontrole');
        $this->get(route('invoices.print', $invoice->uuid))->assertOk()->assertDontSee('K interní kontrole');
        $this->delete(route('invoices.labels.detach', [$invoice->uuid, $label->uuid]))->assertSessionHasNoErrors();
        $this->assertCount(0, $invoice->fresh()->labels);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.label_detached'], 'business_1');
        $other = $this->deliveryMembership('admin', BusinessConnection::Business2)[1];
        app(ActiveBusinessContext::class)->set($other);
        $this->assertSame(0, InvoiceLabel::query()->count());
        $foreignLabel = app(InvoiceLabelService::class)->create(['name' => 'Cizí štítek', 'color' => 'blue', 'is_active' => true]);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.labels.attach', $invoice->uuid), ['label_uuid' => $foreignLabel->uuid])->assertNotFound();
        $this->post(route('invoice-labels.store'), ['name' => 'K interní kontrole', 'color' => 'blue', 'is_active' => '1'])
            ->assertSessionHasErrors('name');
        session()->forget('errors');
        $this->post(route('invoice-labels.store'), ['name' => 'Neplatná barva', 'color' => '#123456', 'is_active' => '1'])
            ->assertSessionHasErrors('color');
        $this->assertSame(1, InvoiceLabel::query()->count());
    }

    public function test_quick_preview_is_authorized_lazy_and_read_only(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $label = app(InvoiceLabelService::class)->create(['name' => 'Náhledový štítek', 'color' => 'blue', 'is_active' => true]);
        app(InvoiceLabelService::class)->attach($invoice->uuid, $label->uuid);
        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $before = [
            'audits' => DB::connection('business_1')->table('audit_logs')->count(),
            'documents' => DB::connection('business_1')->table('invoice_documents')->count(),
            'deliveries' => DB::connection('business_1')->table('invoice_email_deliveries')->count(),
            'links' => DB::connection('business_1')->table('invoice_public_links')->count(),
            'revisions' => DB::connection('business_1')->table('invoice_revisions')->count(),
            'first_viewed_at' => $link->first_viewed_at,
        ];
        app(ActiveBusinessContext::class)->clear();

        $this->get(route('invoices.quick-preview', $invoice->uuid))->assertRedirect(route('login'));
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $index = $this->get(route('invoices.index'))->assertOk();
        $index->assertSee('invoiceQuickPreview()', false)->assertSee('>Náhled<', false);
        $this->get(route('invoices.quick-preview', $invoice->uuid))->assertOk()
            ->assertSee($invoice->document_number)
            ->assertSee($invoice->issuedRevision->variable_symbol)
            ->assertSee('Bezpečná služba')
            ->assertSee('100 Kč')
            ->assertSee('Neuhrazená')
            ->assertSee('Náhledový štítek');

        $this->assertSame($before['audits'], DB::connection('business_1')->table('audit_logs')->count());
        $this->assertSame($before['documents'], DB::connection('business_1')->table('invoice_documents')->count());
        $this->assertSame($before['deliveries'], DB::connection('business_1')->table('invoice_email_deliveries')->count());
        $this->assertSame($before['links'], DB::connection('business_1')->table('invoice_public_links')->count());
        $this->assertSame($before['revisions'], DB::connection('business_1')->table('invoice_revisions')->count());
        $this->assertSame($before['first_viewed_at'], $link->fresh()->first_viewed_at);

        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.quick-preview', $invoice->uuid))->assertOk();

        [$otherAdmin, $otherBusiness] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        $this->actingAs($otherAdmin)->withSession($this->deliveryBusinessSession($otherBusiness));
        $this->get(route('invoices.quick-preview', $invoice->uuid))->assertNotFound();
    }

    public function test_list_omits_duzp_and_hides_due_date_only_after_full_payment(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $overpaidDraft = app(InvoiceDuplicator::class)->duplicate($invoice);
        $overpaid = app(InvoiceIssuer::class)->issue($overpaidDraft->uuid, 1, (string) Str::uuid());
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.index', ['search' => $invoice->document_number]))->assertOk()
            ->assertSee('Splatnost 16. 8. 2026')
            ->assertDontSee('DUZP');

        app(ActiveBusinessContext::class)->set($business);
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '40', 'currency' => 'CZK', 'paid_on' => '2026-08-10', 'payment_method' => 'bank_transfer',
        ]);
        app(ActiveBusinessContext::class)->clear();

        $this->get(route('invoices.index', ['search' => $invoice->document_number]))->assertOk()
            ->assertSee('Splatnost 16. 8. 2026')
            ->assertSee('Částečně uhrazená');

        app(ActiveBusinessContext::class)->set($business);
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '60', 'currency' => 'CZK', 'paid_on' => '2026-08-11', 'payment_method' => 'bank_transfer',
        ]);
        app(ActiveBusinessContext::class)->clear();

        $this->get(route('invoices.index', ['search' => $invoice->document_number]))->assertOk()
            ->assertDontSee('Splatnost 16. 8. 2026')
            ->assertSee('Uhrazená');

        app(ActiveBusinessContext::class)->set($business);
        app(InvoicePaymentService::class)->recordImported(
            $overpaid->uuid,
            (string) Str::uuid(),
            'stage-e-overpayment',
            ['amount' => '101', 'currency' => 'CZK', 'paid_on' => '2026-08-12', 'payment_method' => 'bank_transfer'],
            static fn (): null => null,
        );
        app(ActiveBusinessContext::class)->clear();

        $this->get(route('invoices.index', ['search' => $overpaid->document_number]))->assertOk()
            ->assertDontSee('Splatnost '.$overpaid->due_on->format('j. n. Y'))
            ->assertSee('Přeplacená');
    }

    private function client(string $name, string $registrationNumber, string $email, bool $active = true): Client
    {
        $client = new Client;
        $client->forceFill([
            'type' => 'company', 'display_name' => $name, 'company_name' => $name,
            'registration_number' => $registrationNumber, 'email' => $email,
            'street' => 'Testovací 1', 'city' => 'Brno', 'postal_code' => '60200', 'country_code' => 'CZ',
            'default_currency' => 'CZK', 'default_due_days' => 14,
            'default_payment_method' => 'bank_transfer', 'is_active' => $active,
        ])->save();

        return $client;
    }
}
