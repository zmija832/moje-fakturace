<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Mail\InvoiceIssuedMail;
use App\Models\Business\InvoiceEmailDelivery;
use App\Models\Business\InvoiceEmailSetting;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceEmailTemplateRenderer;
use App\Services\Business\InvoiceMailer;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceEmailSettingsTest extends TestCase
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

    public function test_admin_manages_tenant_local_settings_and_unsafe_template_is_rejected(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoice-email-settings.edit'))->assertOk()->assertSee('{invoice_number}');
        $this->put(route('invoice-email-settings.update'), $this->settings())
            ->assertRedirect(route('invoice-email-settings.edit'))->assertSessionHas('status');
        app(ActiveBusinessContext::class)->set($business);
        $this->assertSame('Fakturace První subjekt', InvoiceEmailSetting::query()->sole()->sender_name);
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice_email_settings.created')->count());
        app(ActiveBusinessContext::class)->clear();

        $this->put(route('invoice-email-settings.update'), [
            ...$this->settings(), 'subject_template' => 'Faktura {{ $invoice->uuid }}',
        ])->assertSessionHasErrors('subject_template');
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoice-email-settings.edit'))->assertForbidden();
        $this->put(route('invoice-email-settings.update'), $this->settings())->assertForbidden();

        [, $otherBusiness] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($otherBusiness);
        $other = app(InvoiceEmailSettingsService::class)->current();
        $this->assertNotSame('Fakturace První subjekt', $other->sender_name);
        $this->assertSame(0, InvoiceEmailSetting::query()->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_email_settings')->count());
        $this->assertSame(0, DB::connection('business_2')->table('invoice_email_settings')->count());
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('invoice_email_settings'));
    }

    public function test_renderer_and_mailer_use_safe_placeholders_active_web_invoice_and_attachment_switch(): void
    {
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $link = app(InvoicePublicLinkService::class)->create($invoice);
        $url = app(InvoicePublicLinkService::class)->url($link);
        app(InvoiceEmailSettingsService::class)->save($this->settings(['attach_pdf' => false, 'include_web_invoice' => true]));
        $rendered = app(InvoiceEmailTemplateRenderer::class)->render($invoice);
        $this->assertSame('Doklad '.$invoice->document_number.' / Příliš žluťoučký klient', $rendered['subject']);
        $this->assertStringContainsString('100 Kč', $rendered['body_text']);
        $this->assertStringContainsString($url, $rendered['body_text']);
        $this->assertStringContainsString('Žluťoučký dodavatel s.r.o.', $rendered['body_text']);
        $this->assertFalse($rendered['attach_pdf']);

        $delivery = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        $this->assertSame($rendered['subject'], $delivery->subject);
        $this->assertSame($rendered['body_text'], $delivery->body_text);
        $this->assertStringContainsString($url, $delivery->body_text);
        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
            return $mail->envelope()->from->name === 'Fakturace První subjekt'
                && $mail->envelope()->replyTo[0]->address === 'reply@example.test'
                && $mail->attachments() === [];
        });

        app(InvoiceEmailSettingsService::class)->save($this->settings(['attach_pdf' => true, 'include_web_invoice' => false]));
        $second = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        $this->assertStringNotContainsString($url, $second->body_text);
        $this->assertSame(2, InvoiceEmailDelivery::query()->count());
        Mail::assertSent(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => count($mail->attachments()) === 1);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'sender_name' => 'Fakturace První subjekt',
            'reply_to' => 'reply@example.test',
            'subject_template' => 'Doklad {invoice_number} / {customer_name}',
            'body_template' => "Dodavatel: {supplier_name}\nČástka: {amount}\nSplatnost: {due_date}\n{web_invoice_url}",
            'signature' => 'Podpis {supplier_name}',
            'attach_pdf' => true,
            'include_web_invoice' => true,
            ...$overrides,
        ];
    }
}
