<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Services\Business\InvoiceDuplicator;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
        Storage::fake('invoice_documents');
        config()->set('business.invoice_test_purge_uuids', []);
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_admin_cancels_issued_invoice_once_and_preserves_all_history(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $number = $invoice->document_number;
        $revisionId = $invoice->issued_revision_id;
        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $publicUrl = app(InvoicePublicLinkService::class)->url($link);
        $correlation = (string) Str::uuid();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $payload = ['reason' => 'Faktura byla vystavena omylem.', 'expected_version' => $invoice->version, 'correlation_uuid' => $correlation];
        $this->post(route('invoices.cancel', $invoice->uuid), $payload)
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status');
        $this->post(route('invoices.cancel', $invoice->uuid), $payload)
            ->assertRedirect(route('invoices.show', $invoice->uuid));

        $stored = DB::connection('business_1')->table('invoices')->where('uuid', $invoice->uuid)->sole();
        $this->assertSame('cancelled', $stored->status);
        $this->assertSame($number, $stored->document_number);
        $this->assertSame($revisionId, $stored->issued_revision_id);
        $this->assertSame($revisionId, $stored->current_revision_id);
        $this->assertSame('Faktura byla vystavena omylem.', $stored->cancellation_reason);
        $this->assertNotNull($stored->cancelled_at);
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_revisions')->where('invoice_id', $stored->id)->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_supplier_snapshots')->where('invoice_revision_id', $revisionId)->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_customer_snapshots')->where('invoice_revision_id', $revisionId)->count());
        $this->assertNotNull(DB::connection('business_1')->table('invoice_public_links')->where('id', $link->id)->value('revoked_at'));
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.cancelled')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.public_link_revoked')->where('subject_uuid', $invoice->uuid)->count());

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('STORNOVÁNO')
            ->assertSee('Faktura byla vystavena omylem.')
            ->assertDontSee('Upravit vystavenou')->assertDontSee('Odeslat klientovi')
            ->assertDontSee('Zaznamenat úhradu')->assertDontSee('Přegenerovat PDF')
            ->assertDontSee('Vytvořit Webfakturu');
        $this->get($publicUrl)->assertNotFound();
        $this->get(route('invoices.index'))->assertOk()->assertDontSee(route('invoices.show', $invoice->uuid), false);
        $this->get(route('invoices.index', ['visibility' => 'cancelled']))->assertOk()
            ->assertSee(route('invoices.show', $invoice->uuid), false)->assertSee('Stornovaná');
        $this->get(route('invoices.index', ['visibility' => 'all']))->assertOk()
            ->assertSee(route('invoices.show', $invoice->uuid), false);
        $this->post(route('invoices.payments.store', $invoice->uuid), [
            'amount' => '1', 'currency' => 'CZK', 'paid_on' => '2026-08-17',
            'payment_method' => 'bank_transfer', 'correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->get(route('invoices.email.form', $invoice->uuid))->assertForbidden();
        $this->post(route('invoices.pdf.generate', $invoice->uuid), [
            'generation_correlation_uuid' => (string) Str::uuid(), 'force_regenerate' => true,
        ])->assertForbidden();
        $this->post(route('invoices.public-link.store', $invoice->uuid))->assertForbidden();
        $this->get(route('invoices.issued-edit.warning', $invoice->uuid))->assertForbidden();

        $this->expectException(QueryException::class);
        DB::connection('business_1')->table('invoices')->where('id', $stored->id)->update(['note' => 'Podvržená změna']);
    }

    public function test_cancellation_requires_reason_and_archived_or_draft_invoice_is_rejected(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->from(route('invoices.show', $invoice->uuid))->post(route('invoices.cancel', $invoice->uuid), [
            'reason' => '', 'expected_version' => $invoice->version, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHasErrors('reason');
        $this->patch(route('invoices.archive', $invoice->uuid))->assertRedirect(route('invoices.index'));
        $this->post(route('invoices.cancel', $invoice->uuid), [
            'reason' => 'Archivovaný doklad.', 'expected_version' => $invoice->version, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertSame('issued', DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->value('status'));
    }

    public function test_cancellation_is_fail_closed_for_payments_permissions_drafts_archives_and_tenants(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.payments.store', $invoice->uuid), [
            'amount' => '10', 'currency' => 'CZK', 'paid_on' => '2026-08-17',
            'payment_method' => 'bank_transfer', 'correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->from(route('invoices.show', $invoice->uuid))->post(route('invoices.cancel', $invoice->uuid), [
            'reason' => 'Nelze kvůli platbě.', 'expected_version' => $invoice->version, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHasErrors('invoice');
        $this->assertSame('issued', DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->value('status'));

        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.cancel', $invoice->uuid), [
            'reason' => 'Zakázáno.', 'expected_version' => $invoice->version, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();

        [, $otherBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($otherBusiness);
        [$foreignDraft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.cancel', $foreignDraft->uuid), [
            'reason' => 'Cizí tenant.', 'expected_version' => 1, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertSame('draft', DB::connection('business_2')->table('invoices')->where('id', $foreignDraft->id)->value('status'));
    }

    public function test_admin_deletes_complete_draft_aggregate_but_audit_remains(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        $invoiceId = $draft->id;
        $revisionId = $draft->current_revision_id;
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->delete(route('invoices.draft.delete', $draft->uuid), ['confirmation' => 'ODSTRANIT'])
            ->assertRedirect(route('invoices.index'))->assertSessionHas('status');
        $this->assertFalse(DB::connection('business_1')->table('invoices')->where('id', $invoiceId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_revisions')->where('id', $revisionId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_items')->where('invoice_revision_id', $revisionId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_draft_operations')->where('invoice_id', $invoiceId)->exists());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.draft_deleted')->where('auditable_uuid', $draft->uuid)->count());
    }

    public function test_viewer_cannot_delete_draft_and_issued_invoice_cannot_use_draft_delete(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->delete(route('invoices.draft.delete', $draft->uuid), ['confirmation' => 'ODSTRANIT'])->assertForbidden();
        $this->assertTrue(DB::connection('business_1')->table('invoices')->where('id', $draft->id)->exists());

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.cancel', $draft->uuid), [
            'reason' => 'Koncept nelze stornovat.', 'expected_version' => 1, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->post(route('invoices.issue', $draft->uuid), [
            'expected_version' => 1, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->delete(route('invoices.draft.delete', $draft->uuid), ['confirmation' => 'ODSTRANIT'])->assertForbidden();
    }

    public function test_allowlisted_test_purge_removes_aggregate_and_pdf_but_never_recycles_number(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $duplicate = app(InvoiceDuplicator::class)->duplicate($invoice);
        $document = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid());
        $firstNumber = $invoice->document_number;
        $firstAllocationId = $invoice->document_number_allocation_id;
        config()->set('business.invoice_test_purge_uuids', [strtolower($invoice->uuid)]);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->delete(route('invoices.test-purge', $invoice->uuid), [
            'confirmation' => 'ODSTRANIT', 'document_number' => $firstNumber,
        ])->assertRedirect(route('invoices.index'))->assertSessionHas('status');
        $this->assertFalse(DB::connection('business_1')->table('invoices')->where('uuid', $invoice->uuid)->exists());
        $this->assertTrue(DB::connection('business_1')->table('document_number_allocations')->where('id', $firstAllocationId)->exists());
        $this->assertSame($invoice->uuid, DB::connection('business_1')->table('document_number_allocations')->where('id', $firstAllocationId)->value('document_uuid'));
        $this->assertFalse(DB::connection('business_1')->table('invoice_documents')->where('invoice_id', $invoice->id)->exists());
        Storage::disk('invoice_documents')->assertMissing($document->storage_path);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.test_purged')->where('auditable_uuid', $invoice->uuid)->count());

        app(ActiveBusinessContext::class)->set($business);
        $next = app(InvoiceIssuer::class)->issue($duplicate->uuid, $duplicate->version, (string) Str::uuid());
        $this->assertNotSame($firstNumber, $next->document_number);
        $this->assertSame(2, DB::connection('business_1')->table('document_number_allocations')->count());
        $this->assertGreaterThan(
            DB::connection('business_1')->table('document_number_allocations')->where('id', $firstAllocationId)->value('sequence_number'),
            DB::connection('business_1')->table('document_number_allocations')->where('id', $next->document_number_allocation_id)->value('sequence_number'),
        );
    }

    public function test_test_purge_requires_server_allowlist_admin_and_safe_invoice(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->delete(route('invoices.test-purge', $invoice->uuid), [
            'confirmation' => 'ODSTRANIT', 'document_number' => $invoice->document_number,
        ])->assertForbidden();
        $this->assertTrue(DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->exists());

        config()->set('business.invoice_test_purge_uuids', [strtolower($invoice->uuid)]);
        $this->post(route('invoices.payments.store', $invoice->uuid), [
            'amount' => '1', 'currency' => 'CZK', 'paid_on' => '2026-08-17',
            'payment_method' => 'bank_transfer', 'correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->from(route('invoices.show', $invoice->uuid))->delete(route('invoices.test-purge', $invoice->uuid), [
            'confirmation' => 'ODSTRANIT', 'document_number' => $invoice->document_number,
        ])->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHasErrors('invoice');
        $this->assertTrue(DB::connection('business_1')->table('invoices')->where('id', $invoice->id)->exists());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_payments')->where('invoice_id', $invoice->id)->count());
    }
}
