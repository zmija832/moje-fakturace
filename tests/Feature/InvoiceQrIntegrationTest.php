<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoicePublicLinkService;
use BaconQrCode\Writer;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceQrIntegrationTest extends TestCase
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

    public function test_snapshot_qr_is_shared_by_internal_detail_print_pdf_model_and_web_invoice(): void
    {
        if (! class_exists(Writer::class)) {
            $this->markTestSkipped('QR knihovna není nainstalována.');
        }

        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, , $liveAccount] = $this->createIssuedInvoice();
        $before = app(InvoiceDocumentViewModelFactory::class)->make($invoice)->toArray();
        $this->assertTrue($before['qr']['available']);
        $this->assertStringContainsString('ACC:CZ6508000000192000145399+GIBACZPX', $before['qr']['payload']);
        $this->assertStringContainsString('AM:100.00', $before['qr']['payload']);
        $this->assertStringContainsString('X-VS:20260001', $before['qr']['payload']);

        $liveAccount->forceFill(['iban' => 'CZ4201000000001234567899', 'bic' => 'KOMBCZPP'])->save();
        $after = app(InvoiceDocumentViewModelFactory::class)->make($invoice->fresh())->toArray();
        $this->assertSame($before['qr']['payload'], $after['qr']['payload']);

        $print = view('business.invoices.print', ['document' => $after, 'archival' => true])->render();
        $this->assertStringContainsString('QR Platba', $print);
        $this->assertStringContainsString($after['qr']['svg_data_uri'], $print);

        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $publicUrl = app(InvoicePublicLinkService::class)->url($link);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()
            ->assertSee('QR Platba')->assertSee('QR kód pro zaplacení faktury');
        auth()->logout();
        $this->get($publicUrl)->assertOk()
            ->assertSee('QR Platba')->assertSee('QR kód pro zaplacení faktury')
            ->assertSee('Neuhrazená');
    }
}
