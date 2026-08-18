<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Services\Business\InvoiceDeletionService;
use App\Services\Business\InvoiceDuplicator;
use App\Services\Business\InvoiceIssuer;
use App\Services\Business\InvoiceMailer;
use App\Services\Business\InvoicePaymentService;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->delete(route('invoices.delete', $draft->uuid), ['confirmation' => '1'])
            ->assertRedirect(route('invoices.index'))->assertSessionHas('status');
        $this->assertFalse(DB::connection('business_1')->table('invoices')->where('id', $invoiceId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_revisions')->where('id', $revisionId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_items')->where('invoice_revision_id', $revisionId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('invoice_draft_operations')->where('invoice_id', $invoiceId)->exists());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.deleted')->where('auditable_uuid', $draft->uuid)->count());
    }

    public function test_viewer_cannot_delete_invoice_and_other_tenant_uuid_is_not_visible(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.show', $draft->uuid))->assertOk()
            ->assertDontSee('Odstranit koncept')->assertDontSee('Smazat fakturu');
        $this->delete(route('invoices.delete', $draft->uuid), ['confirmation' => '1'])->assertForbidden();
        $this->assertTrue(DB::connection('business_1')->table('invoices')->where('id', $draft->id)->exists());

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->post(route('invoices.cancel', $draft->uuid), [
            'reason' => 'Koncept nelze stornovat.', 'expected_version' => 1, 'correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        [, $otherBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($otherBusiness);
        [$foreign] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->delete(route('invoices.delete', $foreign->uuid), ['confirmation' => '1'])->assertNotFound();
        $this->assertTrue(DB::connection('business_2')->table('invoices')->where('id', $foreign->id)->exists());
    }

    public function test_admin_deletes_issued_invoice_with_complete_aggregate_and_reuses_released_number(): void
    {
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $duplicate = app(InvoiceDuplicator::class)->duplicate($invoice);
        $document = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid());
        app(InvoicePublicLinkService::class)->create($invoice);
        app(InvoicePaymentService::class)->record($invoice->uuid, (string) Str::uuid(), [
            'amount' => '10', 'currency' => 'CZK', 'paid_on' => '2026-08-17', 'payment_method' => 'bank_transfer',
        ]);
        app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        DB::connection('business_1')->table('invoice_issued_revision_operations')->insert([
            'correlation_uuid' => (string) Str::uuid(), 'invoice_id' => $invoice->id,
            'invoice_revision_id' => $invoice->issued_revision_id, 'created_by_actor' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = $invoice->id;
        $revisionIds = DB::connection('business_1')->table('invoice_revisions')->where('invoice_id', $invoiceId)->pluck('id');
        $sequenceId = $invoice->document_sequence_id;
        $firstNumber = $invoice->document_number;
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('Smazat fakturu')
            ->assertDontSee('Trvale odstranit testovací fakturu')->assertDontSee('ODSTRANIT');
        try {
            DB::connection('business_1')->table('invoices')->where('id', $invoiceId)->delete();
            $this->fail('Přímý DELETE vystavené faktury musí zůstat blokovaný databázovým guardem.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->from(route('invoices.show', $invoice->uuid))->delete(route('invoices.delete', $invoice->uuid))
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHasErrors('confirmation');
        $this->delete(route('invoices.delete', $invoice->uuid), ['confirmation' => '1'])
            ->assertRedirect(route('invoices.index'))->assertSessionHas('status');
        $this->assertFalse(DB::connection('business_1')->table('invoices')->where('uuid', $invoice->uuid)->exists());
        foreach (['invoice_documents', 'invoice_email_deliveries', 'invoice_payments', 'invoice_public_links', 'invoice_draft_operations', 'invoice_issued_revision_operations'] as $table) {
            $this->assertFalse(DB::connection('business_1')->table($table)->where('invoice_id', $invoiceId)->exists(), $table);
        }
        foreach (['invoice_items', 'invoice_vat_summaries', 'invoice_vat_snapshots', 'invoice_supplier_snapshots', 'invoice_customer_snapshots', 'invoice_bank_account_snapshots'] as $table) {
            $this->assertFalse(DB::connection('business_1')->table($table)->whereIn('invoice_revision_id', $revisionIds)->exists(), $table);
        }
        $this->assertFalse(DB::connection('business_1')->table('invoice_revisions')->where('invoice_id', $invoiceId)->exists());
        $this->assertFalse(DB::connection('business_1')->table('document_number_allocations')->where('document_uuid', $invoice->uuid)->exists());
        Storage::disk('invoice_documents')->assertMissing($document->storage_path);
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.deleted')->where('auditable_uuid', $invoice->uuid)->sole();
        $this->assertTrue((bool) json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR)['allocation_released']);
        $this->assertSame(1, DB::connection('business_1')->table('document_sequences')->where('id', $sequenceId)->value('next_number'));

        app(ActiveBusinessContext::class)->set($business);
        $next = app(InvoiceIssuer::class)->issue($duplicate->uuid, $duplicate->version, (string) Str::uuid());
        $this->assertSame($firstNumber, $next->document_number);
        $this->assertSame(1, DB::connection('business_1')->table('document_number_allocations')->count());
        app(ActiveBusinessContext::class)->clear();
        $this->delete(route('invoices.delete', $invoice->uuid), ['confirmation' => '1'])->assertNotFound();
    }

    #[DataProvider('tailReleaseCases')]
    public function test_number_sequence_rewinds_only_over_deleted_contiguous_tail(array $deletedNumbers, int $expectedNext, array $remainingNumbers): void
    {
        [$admin, $business] = $this->deliveryMembership();
        $this->actingAs($admin);
        app(ActiveBusinessContext::class)->set($business);
        [$first] = $this->createIssuedInvoice();
        $invoices = [1 => $first];
        for ($number = 2; $number <= 6; $number++) {
            $draft = app(InvoiceDuplicator::class)->duplicate($first);
            $invoices[$number] = app(InvoiceIssuer::class)->issue($draft->uuid, $draft->version, (string) Str::uuid());
        }
        $nextDraft = app(InvoiceDuplicator::class)->duplicate($first);
        $sequenceId = $first->document_sequence_id;

        foreach ($deletedNumbers as $number) {
            app(InvoiceDeletionService::class)->delete($invoices[$number]->uuid);
        }

        $this->assertSame($remainingNumbers, DB::connection('business_1')->table('document_number_allocations')
            ->where('document_sequence_id', $sequenceId)->orderBy('sequence_number')->pluck('sequence_number')->map(fn ($value): int => (int) $value)->all());
        $this->assertSame($expectedNext, (int) DB::connection('business_1')->table('document_sequences')->where('id', $sequenceId)->value('next_number'));

        $issued = app(InvoiceIssuer::class)->issue($nextDraft->uuid, $nextDraft->version, (string) Str::uuid());
        $this->assertSame($expectedNext, (int) DB::connection('business_1')->table('document_number_allocations')
            ->where('id', $issued->document_number_allocation_id)->value('sequence_number'));
        $allocations = DB::connection('business_1')->table('document_number_allocations')->where('document_sequence_id', $sequenceId)->get();
        $this->assertSame($allocations->count(), $allocations->pluck('sequence_number')->unique()->count());
    }

    public static function tailReleaseCases(): array
    {
        return [
            'delete last 6' => [[6], 6, [1, 2, 3, 4, 5]],
            'delete tail 5 and 6' => [[5, 6], 5, [1, 2, 3, 4]],
            'delete all' => [[1, 2, 3, 4, 5, 6], 1, []],
            'delete middle 3' => [[3], 7, [1, 2, 4, 5, 6]],
        ];
    }
}
