<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Models\Business\InvoicePublicLink;
use App\Services\Business\InvoicePdfGenerator;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoicePublicLinkTest extends TestCase
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

    public function test_admin_creates_public_link_and_anonymous_client_reads_only_immutable_invoice(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, $client] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->post(route('invoices.public-link.store', $invoice->uuid))
            ->assertRedirect(route('invoices.show', $invoice->uuid))->assertSessionHas('status');
        app(ActiveBusinessContext::class)->set($business);
        $link = InvoicePublicLink::query()->sole();
        $token = $link->token_ciphertext;
        $url = app(InvoicePublicLinkService::class)->url($link);
        $stored = DB::connection('business_1')->table('invoice_public_links')->sole();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertNotSame($token, $stored->token_ciphertext);
        $this->assertNull($stored->revoked_at);
        $client->forceFill(['display_name' => 'Živý klient změněn'])->save();
        app(ActiveBusinessContext::class)->clear();
        auth()->logout();

        $this->get($url)->assertOk()
            ->assertSee('Faktura '.$invoice->document_number)
            ->assertSee('100 Kč')
            ->assertDontSee('100 CZK')
            ->assertSee('Příliš žluťoučký klient')->assertDontSee('Živý klient změněn')
            ->assertSee('Neplátce DPH')->assertSee('Bezpečná služba')
            ->assertDontSee('Auditní historie')->assertDontSee('business_1')->assertDontSee($invoice->uuid);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.public_link_created')->count());
        $audit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.public_link_created')->sole();
        $this->assertStringNotContainsString($token, (string) $audit->new_values);
        $this->assertStringNotContainsString($stored->token_hash, (string) $audit->new_values);
    }

    public function test_public_pdf_uses_existing_current_revision_artifact_and_never_regenerates(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $document = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid());
        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $url = app(InvoicePublicLinkService::class)->url($link);
        app(ActiveBusinessContext::class)->clear();
        auth()->logout();

        $this->get($url)->assertOk()->assertSee('Stáhnout PDF');
        $this->get($url.'/pdf')->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame(1, DB::connection('business_1')->table('invoice_documents')->count());
        Storage::disk('invoice_documents')->assertExists($document->storage_path);
    }

    public function test_revoke_and_regenerate_invalidate_old_tokens_and_are_audited(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $first = app(InvoicePublicLinkService::class)->create($invoice);
        $firstUrl = app(InvoicePublicLinkService::class)->url($first);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->post(route('invoices.public-link.regenerate', $invoice->uuid))->assertSessionHas('status');
        app(ActiveBusinessContext::class)->set($business);
        $second = InvoicePublicLink::query()->active()->sole();
        $secondUrl = app(InvoicePublicLinkService::class)->url($second);
        app(ActiveBusinessContext::class)->clear();
        auth()->logout();
        $this->get($firstUrl)->assertNotFound();
        $this->get($secondUrl)->assertOk();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->delete(route('invoices.public-link.revoke', $invoice->uuid))->assertSessionHas('status');
        auth()->logout();
        $this->get($secondUrl)->assertNotFound();
        $this->assertSame(0, DB::connection('business_1')->table('invoice_public_links')->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.public_link_regenerated')->count());
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.public_link_revoked')->count());
    }

    public function test_draft_invalid_token_and_other_tenant_data_fail_closed(): void
    {
        [$adminOne, $businessOne] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($businessOne);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($adminOne)->withSession($this->deliveryBusinessSession($businessOne));
        $this->post(route('invoices.public-link.store', $draft->uuid))->assertForbidden();
        $this->assertSame(0, DB::connection('business_1')->table('invoice_public_links')->count());

        [$adminTwo, $businessTwo] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($businessTwo);
        [$invoiceTwo] = $this->createIssuedInvoice();
        $linkTwo = app(InvoicePublicLinkService::class)->create($invoiceTwo);
        $urlTwo = app(InvoicePublicLinkService::class)->url($linkTwo);
        app(ActiveBusinessContext::class)->clear();
        auth()->logout();

        $this->get('/f/'.str_repeat('A', 43))->assertNotFound();
        $this->get($urlTwo)->assertOk()->assertSee($invoiceTwo->document_number)->assertDontSee('business_1');
        $this->assertSame(0, DB::connection('business_1')->table('invoice_public_links')->count());
        $this->assertSame(1, DB::connection('business_2')->table('invoice_public_links')->count());
    }

    public function test_public_routes_are_read_only_and_rate_limited(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $url = app(InvoicePublicLinkService::class)->url($link);
        app(ActiveBusinessContext::class)->clear();
        auth()->logout();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.60']);

        foreach (range(1, 60) as $_request) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertTooManyRequests();
        $this->post($url)->assertMethodNotAllowed();
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('public-invoices.show')->methods());
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('public-invoices.pdf')->methods());
    }
}
