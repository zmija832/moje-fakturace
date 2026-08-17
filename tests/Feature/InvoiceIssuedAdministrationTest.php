<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Enums\BusinessConnection;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoiceRevision;
use App\Services\Business\InvoicePdfGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceIssuedAdministrationTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
        Storage::fake(InvoicePdfGenerator::DISK);
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_admin_confirmation_creates_new_issued_revision_pdf_and_preserves_original(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $number = $invoice->document_number;
        $original = $invoice->issuedRevision;
        $originalSnapshotName = $original->customerSnapshot->display_name;
        $oldDocument = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid());
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.issued-edit', $invoice->uuid))
            ->assertRedirect(route('invoices.issued-edit.warning', $invoice->uuid));
        $this->get(route('invoices.issued-edit.warning', $invoice->uuid))->assertOk()
            ->assertSee('Významná účetní operace')->assertSee('Původní revize');
        $this->post(route('invoices.issued-edit.confirm', $invoice->uuid))
            ->assertRedirect(route('invoices.issued-edit', $invoice->uuid));
        $this->get(route('invoices.issued-edit', $invoice->uuid))->assertOk()
            ->assertSee('Upravujete již vystavený doklad')->assertSee($number);

        app(ActiveBusinessContext::class)->set($business);
        $payload = $this->payload($invoice);
        app(ActiveBusinessContext::class)->clear();
        $payload['note'] = 'Opravená poznámka vystavené faktury';
        $this->put(route('invoices.issued-update', $invoice->uuid), $payload)
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status');

        app(ActiveBusinessContext::class)->set($business);
        $fresh = Invoice::query()->whereKey($invoice->id)->firstOrFail();
        $this->assertSame($number, $fresh->document_number);
        $this->assertNotSame($original->id, $fresh->issued_revision_id);
        $this->assertSame($fresh->issued_revision_id, $fresh->current_revision_id);
        $this->assertSame(2, InvoiceRevision::query()->where('invoice_id', $fresh->id)->count());
        $this->assertSame('Poznámka faktury', $original->fresh()->note);
        $this->assertSame($originalSnapshotName, $original->customerSnapshot->fresh()->display_name);
        $this->assertSame('Opravená poznámka vystavené faktury', $fresh->issuedRevision->note);
        $this->assertSame(2, InvoiceDocument::query()->where('invoice_id', $fresh->id)->count());
        $this->assertDatabaseHas('invoice_documents', ['id' => $oldDocument->id, 'invoice_revision_id' => $original->id], 'business_1');
        $this->assertDatabaseHas('invoice_documents', ['invoice_id' => $fresh->id, 'invoice_revision_id' => $fresh->issued_revision_id], 'business_1');
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')
            ->where('event', 'invoice.issued_revision_created')->where('auditable_uuid', $invoice->uuid)->count());
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.issued_revision_created')->sole();
        $this->assertSame('central-user:'.$admin->id, $audit->actor_user_uuid);
    }

    public function test_viewer_cannot_enter_or_submit_issued_admin_edit(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $payload = $this->payload($invoice);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.issued-edit.warning', $invoice->uuid))->assertForbidden();
        $this->post(route('invoices.issued-edit.confirm', $invoice->uuid))->assertForbidden();
        $this->put(route('invoices.issued-update', $invoice->uuid), $payload)->assertForbidden();
        app(ActiveBusinessContext::class)->set($business);
        $this->assertSame(1, InvoiceRevision::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_issued_admin_edit_is_tenant_isolated(): void
    {
        [$admin, $businessOne] = $this->deliveryMembership();
        [, $businessTwo] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        [$foreignInvoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($businessOne));
        $this->get(route('invoices.issued-edit.warning', $foreignInvoice->uuid))->assertNotFound();
        $this->post(route('invoices.issued-edit.confirm', $foreignInvoice->uuid))->assertNotFound();
    }

    public function test_http_issuance_creates_first_pdf(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->post(route('invoices.issue', $draft->uuid), [
            'expected_version' => 1,
            'correlation_uuid' => (string) Str::uuid(),
            'document_sequence_uuid' => null,
        ])->assertRedirect(route('invoices.show', $draft->uuid))->assertSessionHas('status');
        app(ActiveBusinessContext::class)->set($business);
        $issued = Invoice::query()->whereKey($draft->id)->firstOrFail();
        $this->assertSame('issued', $issued->status->value);
        $this->assertDatabaseHas('invoice_documents', [
            'invoice_id' => $issued->id,
            'invoice_revision_id' => $issued->issued_revision_id,
        ], 'business_1');

    }

    public function test_pdf_failure_after_http_issuance_does_not_rollback_invoice(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $generator = Mockery::mock(InvoicePdfGenerator::class);
        $generator->shouldReceive('generate')->once()->andThrow(InvoicePdfGenerationFailed::create());
        $this->app->instance(InvoicePdfGenerator::class, $generator);
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.issue', $draft->uuid), [
            'expected_version' => 1,
            'correlation_uuid' => (string) Str::uuid(),
            'document_sequence_uuid' => null,
        ])->assertRedirect(route('invoices.show', $draft->uuid))->assertSessionHas('error');
        app(ActiveBusinessContext::class)->set($business);
        $this->assertSame('issued', Invoice::query()->whereKey($draft->id)->value('status')->value);
        $this->assertSame(0, InvoiceDocument::query()->count());
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice): array
    {
        $revision = $invoice->issuedRevision;

        return [
            'customer_uuid' => $revision->customerSnapshot->source_client_uuid,
            'bank_account_uuid' => $revision->bankAccountSnapshot?->source_bank_account_uuid,
            'currency' => $revision->currency,
            'issued_on' => $revision->issued_on->format('Y-m-d'),
            'taxable_supply_on' => $revision->taxable_supply_on->format('Y-m-d'),
            'due_on' => $revision->due_on->format('Y-m-d'),
            'payment_method' => $revision->payment_method->value,
            'variable_symbol' => $revision->variable_symbol,
            'note' => $revision->note,
            'invoice_discount_type' => $revision->invoice_discount_type->value,
            'invoice_discount_value' => $revision->invoice_discount_value,
            'items' => $revision->items->map(fn ($item): array => [
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type->value,
                'discount_value' => $item->discount_value,
            ])->all(),
            'version' => $invoice->version,
            'correlation_uuid' => (string) Str::uuid(),
            'admin_edit_confirmation' => '1',
        ];
    }
}
