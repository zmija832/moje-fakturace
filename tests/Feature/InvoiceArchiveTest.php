<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Services\Business\InvoiceIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceArchiveTest extends TestCase
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

    public function test_admin_archives_draft_without_deleting_immutable_history(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft, $client] = $this->createIssuedInvoice(false);
        $revisionId = $draft->current_revision_id;
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->patch(route('invoices.archive', $draft->uuid))
            ->assertRedirect(route('invoices.index'))->assertSessionHas('status');

        $stored = DB::connection('business_1')->table('invoices')->where('uuid', $draft->uuid)->sole();
        $this->assertNotNull($stored->archived_at);
        $this->assertSame(1, DB::connection('business_1')->table('invoice_revisions')->where('invoice_id', $stored->id)->count());
        $this->assertSame($revisionId, DB::connection('business_1')->table('invoices')->where('id', $stored->id)->value('current_revision_id'));
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')
            ->where('event', 'invoice.draft_archived')->where('auditable_uuid', $draft->uuid)->count());

        $detailUrl = route('invoices.show', $draft->uuid);
        $this->get(route('invoices.index'))->assertOk()->assertDontSee($detailUrl, false);
        $this->get(route('invoices.index', ['status' => 'archived']))->assertOk()
            ->assertSee($detailUrl, false)->assertSee($client->display_name)->assertSee('Archivovaný koncept');
        $this->get(route('invoices.show', $draft->uuid))->assertOk()
            ->assertSee('Archivovaný koncept')->assertDontSee('Upravit návrh')->assertDontSee('Vystavit fakturu');
        $this->get(route('invoices.edit', $draft->uuid))->assertForbidden();
        $this->patch(route('invoices.archive', $draft->uuid))->assertForbidden();
    }

    public function test_issued_viewer_and_other_tenant_cannot_archive(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        $issued = app(InvoiceIssuer::class)->issue($draft->uuid, 1, (string) Str::uuid());
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->patch(route('invoices.archive', $issued->uuid))->assertForbidden();
        $this->assertNull(DB::connection('business_1')->table('invoices')->where('id', $issued->id)->value('archived_at'));

        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->patch(route('invoices.archive', $issued->uuid))->assertForbidden();

        [, $otherBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($otherBusiness);
        [$foreignDraft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->patch(route('invoices.archive', $foreignDraft->uuid))->assertNotFound();
        $this->assertNull(DB::connection('business_2')->table('invoices')->where('id', $foreignDraft->id)->value('archived_at'));

        $this->assertNull(app('router')->getRoutes()->getByName('invoices.destroy'));
        $this->assertTrue(Schema::connection('business_1')->hasColumn('invoices', 'archived_at'));
        $this->assertTrue(Schema::connection('business_2')->hasColumn('invoices', 'archived_at'));
        $this->assertFalse(Schema::connection('central')->hasColumn('invoices', 'archived_at'));
    }
}
