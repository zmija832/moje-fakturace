<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Enums\InvoiceMailStyle;
use App\Enums\InvoicePdfStyle;
use App\Models\Business\InvoiceDocument;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceMailDesignRenderer;
use App\Services\Business\InvoicePdfRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDesignPreviewTest extends TestCase
{
    use CreatesInvoiceDeliveryFixtures;
    use InteractsWithBusinessDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshBusinessTestDatabases();
        Storage::fake('invoice_documents');
        Storage::fake(InvoiceMailDesignRenderer::LOGO_DISK);
    }

    protected function tearDown(): void
    {
        app(ActiveBusinessContext::class)->clear();
        parent::tearDown();
    }

    public function test_email_preview_uses_unsaved_values_without_mail_delivery_token_or_mutation(): void
    {
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        $before = $this->sideEffectCounts('business_1');

        $response = $this->actingAs($admin)
            ->withSession($this->deliveryBusinessSession($business))
            ->post(route('invoice-email-settings.preview'), $this->settings([
                'template_style' => 'elegant',
                'sender_name' => 'Neuložený dodavatel',
                'body_template' => "Bezpečný <script>alert(1)</script> text.\n{web_invoice_url}",
                'signature_name' => '<script>podpis</script>',
            ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Neuložený dodavatel')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('https://example.invalid/webfaktura', false);

        foreach (InvoiceMailStyle::cases() as $style) {
            $styleResponse = $this->post(route('invoice-email-settings.preview'), $this->settings([
                'template_style' => $style->value,
            ]));
            $styleResponse->assertOk()->assertSee((string) $style->theme()['accent'], false);
        }

        Mail::assertNothingSent();
        $this->assertSame($before, $this->sideEffectCounts('business_1'));
    }

    public function test_pdf_preview_renders_all_styles_without_accounting_side_effects(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        $before = $this->sideEffectCounts('business_1');

        foreach (InvoicePdfStyle::cases() as $style) {
            $response = $this->actingAs($admin)
                ->withSession($this->deliveryBusinessSession($business))
                ->post(route('invoice-email-settings.pdf-preview'), ['pdf_style' => $style->value]);

            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertSame($before, $this->sideEffectCounts('business_1'));
        }
    }

    public function test_real_invoice_data_and_nonpayer_qr_render_in_all_pdf_styles_without_rewriting_history(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $data = app(InvoiceDocumentViewModelFactory::class)->make($invoice)->toArray();
        $renderer = app(InvoicePdfRenderer::class);
        $documentsBefore = InvoiceDocument::query()->pluck('sha256', 'uuid')->all();

        foreach (InvoicePdfStyle::cases() as $style) {
            $html = $renderer->renderHtml($data, $style, true);
            $this->assertStringContainsString((string) $invoice->document_number, $html);
            $this->assertStringContainsString($data['supplier']['name'], $html);
            $this->assertStringContainsString($data['customer']['name'], $html);
            $this->assertStringContainsString($data['totals']['grand_total'], $html);
            $this->assertStringContainsString($data['due_on'], $html);
            $this->assertStringContainsString((string) $data['variable_symbol'], $html);
            $this->assertStringContainsString('Neplátce DPH', $html);
            $this->assertStringContainsString('QR Platba', $html);
            $this->assertStringStartsWith('%PDF-', $renderer->renderPdf($data, $style));
        }

        $longDocument = $data;
        $longDocument['items'] = [];
        foreach (range(1, 45) as $position) {
            $item = $data['items'][($position - 1) % count($data['items'])];
            $item['position'] = $position;
            $item['description'] .= ' – řádek '.$position;
            $longDocument['items'][] = $item;
        }
        $this->assertStringStartsWith('%PDF-', $renderer->renderPdf($longDocument, InvoicePdfStyle::Compact));

        app(InvoiceEmailSettingsService::class)->save($this->settings(['pdf_style' => 'corporate']));
        $this->assertSame($documentsBefore, InvoiceDocument::query()->pluck('sha256', 'uuid')->all());
    }

    public function test_private_logo_preview_is_tenant_scoped_and_preview_routes_require_authentication(): void
    {
        [$admin, $first] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($first);
        $firstSetting = app(InvoiceEmailSettingsService::class)->save($this->settings(['pdf_style' => 'corporate']), $this->png('logo.png'));
        $this->assertSame('corporate', $firstSetting->pdf_style);
        app(ActiveBusinessContext::class)->clear();

        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($first))
            ->get(route('invoice-email-settings.logo'))
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $first);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($first));
        $this->post(route('invoice-email-settings.preview'), $this->settings())->assertForbidden();
        $this->post(route('invoice-email-settings.pdf-preview'), ['pdf_style' => 'classic'])->assertForbidden();
        $this->get(route('invoice-email-settings.logo'))->assertForbidden();

        [$secondAdmin, $second] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        $this->actingAs($secondAdmin)->withSession($this->deliveryBusinessSession($second))
            ->get(route('invoice-email-settings.logo'))->assertNotFound();
        app(ActiveBusinessContext::class)->set($second);
        $this->assertSame('classic', app(InvoiceEmailSettingsService::class)->current()->pdf_style);
        app(ActiveBusinessContext::class)->clear();

        auth()->logout();
        $this->post(route('invoice-email-settings.preview'), $this->settings())->assertRedirect(route('login'));
        $this->post(route('invoice-email-settings.pdf-preview'), ['pdf_style' => 'classic'])->assertRedirect(route('login'));
    }

    /** @return array<string, int> */
    private function sideEffectCounts(string $connection): array
    {
        return [
            'invoices' => DB::connection($connection)->table('invoices')->count(),
            'revisions' => DB::connection($connection)->table('invoice_revisions')->count(),
            'documents' => DB::connection($connection)->table('invoice_documents')->count(),
            'allocations' => DB::connection($connection)->table('document_number_allocations')->count(),
            'deliveries' => DB::connection($connection)->table('invoice_email_deliveries')->count(),
            'public_links' => DB::connection($connection)->table('invoice_public_links')->count(),
            'audit_logs' => DB::connection($connection)->table('audit_logs')->count(),
        ];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'sender_name' => 'Ukázkový dodavatel',
            'reply_to' => 'reply@example.test',
            'template_style' => 'modern',
            'pdf_style' => 'classic',
            'subject_template' => 'Faktura {invoice_number}',
            'body_template' => 'Faktura pro {customer_name}. {web_invoice_url}',
            'signature' => null,
            'signature_name' => 'Milan Zítek',
            'signature_role' => null,
            'signature_phone' => null,
            'signature_email' => null,
            'signature_web' => null,
            'signature_text' => 'S pozdravem',
            'attach_pdf' => true,
            'include_web_invoice' => true,
            'remove_logo' => false,
            ...$overrides,
        ];
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    }
}
