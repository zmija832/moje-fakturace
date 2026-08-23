<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessConnection;
use App\Enums\InvoiceMailStyle;
use App\Mail\InvoiceIssuedMail;
use App\Models\Business\InvoiceEmailDelivery;
use App\Models\Business\InvoiceEmailSetting;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceEmailTemplateRenderer;
use App\Services\Business\InvoiceMailDesignRenderer;
use App\Services\Business\InvoiceMailer;
use App\Services\Business\InvoicePublicLinkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
        Storage::fake(InvoiceMailDesignRenderer::LOGO_DISK);
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
        $this->get(route('invoice-email-settings.edit'))
            ->assertOk()
            ->assertSee('{invoice_number}')
            ->assertSee('Přiložit PDF jako přílohu e-mailu');
        $this->put(route('invoice-email-settings.update'), $this->settings(['pdf_style' => 'corporate']))
            ->assertRedirect(route('invoice-email-settings.edit'))->assertSessionHas('status');
        app(ActiveBusinessContext::class)->set($business);
        $this->assertSame('Fakturace První subjekt', InvoiceEmailSetting::query()->sole()->sender_name);
        $this->assertSame('corporate', InvoiceEmailSetting::query()->sole()->pdf_style);
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
        $pdfUrl = app(InvoicePublicLinkService::class)->activePdfUrlForInvoice($invoice);
        $this->assertNotNull($url);
        $this->assertNotNull($pdfUrl);
        app(InvoiceEmailSettingsService::class)->save($this->settings(['attach_pdf' => false, 'include_web_invoice' => true]));
        $rendered = app(InvoiceEmailTemplateRenderer::class)->render($invoice);
        $this->assertSame('Doklad '.$invoice->document_number.' / Příliš žluťoučký klient', $rendered['subject']);
        $this->assertStringContainsString('100 Kč', $rendered['body_text']);
        $this->assertStringContainsString($url, $rendered['body_text']);
        $this->assertSame(1, substr_count($rendered['body_text'], $url));
        $this->assertStringContainsString('Žluťoučký dodavatel s.r.o.', $rendered['body_text']);
        $this->assertStringContainsString('Fakturu můžete zobrazit online pomocí tlačítka níže.', $rendered['body_html']);
        $this->assertStringContainsString('href="'.$url.'"', $rendered['body_html']);
        $this->assertStringContainsString('Zobrazit fakturu', $rendered['body_html']);
        $this->assertStringContainsString('tohoto odkazu', $rendered['body_html']);
        $this->assertStringNotContainsString('>'.$url.'</a>', $rendered['body_html']);
        $this->assertStringNotContainsString($pdfUrl, $rendered['body_html']);
        $this->assertStringNotContainsString('Stáhnout PDF faktury', $rendered['body_html']);
        $this->assertFalse($rendered['attach_pdf']);

        $delivery = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        $this->assertSame($rendered['subject'], $delivery->subject);
        $this->assertSame($rendered['body_text'], $delivery->body_text);
        $this->assertStringContainsString($url, $delivery->body_text);
        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail) use ($url, $pdfUrl): bool {
            return $mail->envelope()->from->name === 'Fakturace První subjekt'
                && $mail->envelope()->replyTo[0]->address === 'reply@example.test'
                && $mail->attachments() === []
                && str_contains($mail->render(), 'href="'.$url.'"')
                && ! str_contains($mail->render(), $pdfUrl);
        });

        app(InvoiceEmailSettingsService::class)->save($this->settings(['attach_pdf' => true, 'include_web_invoice' => true]));
        $second = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        $this->assertStringContainsString($url, $second->body_text);
        $this->assertStringContainsString('href="'.$url.'"', $second->body_html);
        $this->assertStringNotContainsString($pdfUrl, $second->body_html);
        $this->assertStringNotContainsString('Stáhnout PDF faktury', $second->body_html);
        $this->assertSame(2, InvoiceEmailDelivery::query()->count());
        Mail::assertSent(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => count($mail->attachments()) === 1
            && str_contains($mail->render(), 'href="'.$url.'"')
            && ! str_contains($mail->render(), $pdfUrl));
    }

    public function test_default_and_all_five_email_styles_render_from_one_design_system(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $service = app(InvoiceEmailSettingsService::class);

        $this->assertSame(InvoiceMailStyle::Modern->value, $service->current()->template_style);
        foreach (InvoiceMailStyle::cases() as $style) {
            $service->save($this->settings(['template_style' => $style->value]));
            $rendered = app(InvoiceEmailTemplateRenderer::class)->render($invoice);
            $this->assertStringContainsString('Faktura '.$invoice->document_number, $rendered['body_html']);
            $this->assertStringContainsString((string) $style->theme()['accent'], $rendered['body_html']);
            $this->assertStringContainsString('Webfaktura:', $rendered['body_text']);
            $this->assertStringContainsString('tohoto odkazu', $rendered['body_html']);
            $this->assertStringNotContainsString('Stáhnout PDF faktury', $rendered['body_html']);
        }
    }

    public function test_logo_upload_is_private_tenant_scoped_and_invalid_files_are_rejected(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));
        $logo = $this->png('logo.png');

        $this->put(route('invoice-email-settings.update'), [
            ...$this->settings(['template_style' => 'modern']),
            'logo' => $logo,
            'remove_logo' => '0',
        ])->assertRedirect(route('invoice-email-settings.edit'));

        app(ActiveBusinessContext::class)->set($business);
        $setting = InvoiceEmailSetting::query()->sole();
        $this->assertStringStartsWith($business->uuid.'/', $setting->logo_path);
        Storage::disk(InvoiceMailDesignRenderer::LOGO_DISK)->assertExists($setting->logo_path);
        $this->assertNotNull(app(InvoiceMailDesignRenderer::class)->logoAttachment());
        [$invoice] = $this->createIssuedInvoice();
        $rendered = app(InvoiceEmailTemplateRenderer::class)->render($invoice);
        $this->assertStringContainsString('<img', $rendered['body_html']);
        $this->assertStringContainsString('__MAIL_LOGO__', $rendered['body_html']);
        Mail::fake();
        app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '<img') && ! str_contains($html, '__MAIL_LOGO__');
        });
        app(ActiveBusinessContext::class)->clear();

        $this->put(route('invoice-email-settings.update'), [
            ...$this->settings(['template_style' => 'modern']),
            'logo' => UploadedFile::fake()->createWithContent('fake.png', '<?php echo "bad";'),
            'remove_logo' => '0',
        ])->assertSessionHasErrors('logo');
        $this->put(route('invoice-email-settings.update'), [
            ...$this->settings(['template_style' => 'modern']),
            'logo' => $this->png('huge.png', 2100),
            'remove_logo' => '0',
        ])->assertSessionHasErrors('logo');
    }

    public function test_structured_signature_is_escaped_and_missing_logo_never_breaks_render(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(InvoiceEmailSettingsService::class)->save($this->settings([
            'template_style' => 'corporate',
            'signature' => null,
            'signature_name' => '<script>alert(1)</script>',
            'signature_role' => 'Jednatel',
            'signature_text' => "S pozdravem\nBez HTML",
        ]));
        InvoiceEmailSetting::query()->update(['logo_path' => $business->uuid.'/missing.png']);

        $rendered = app(InvoiceEmailTemplateRenderer::class)->render($invoice);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered['body_html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered['body_html']);
        $this->assertStringContainsString('Bez HTML', $rendered['body_text']);
        $this->assertStringNotContainsString('__MAIL_LOGO__', $rendered['body_html']);
    }

    public function test_logo_style_and_signature_are_isolated_per_business(): void
    {
        [, $first] = $this->deliveryMembership();
        [, $second] = $this->deliveryMembership(connection: BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($first);
        app(InvoiceEmailSettingsService::class)->save(
            $this->settings(['template_style' => 'elegant', 'signature_name' => 'První podpis']),
            $this->png('first.png'),
        );
        $firstSetting = InvoiceEmailSetting::query()->sole();

        app(ActiveBusinessContext::class)->set($second);
        $secondSetting = app(InvoiceEmailSettingsService::class)->current();

        $this->assertSame('elegant', $firstSetting->template_style);
        $this->assertSame('První podpis', $firstSetting->signature_name);
        $this->assertNotNull($firstSetting->logo_path);
        $this->assertSame('modern', $secondSetting->template_style);
        $this->assertNull($secondSetting->signature_name);
        $this->assertNull($secondSetting->logo_path);
        $this->assertStringStartsWith($first->uuid.'/', $firstSetting->logo_path);
    }

    public function test_mail_design_schema_is_present_only_in_both_business_databases(): void
    {
        foreach (['business_1', 'business_2'] as $connection) {
            $this->assertTrue(Schema::connection($connection)->hasColumns('invoice_email_settings', [
                'template_style', 'logo_path', 'signature_name', 'signature_role',
                'pdf_style',
                'signature_phone', 'signature_email', 'signature_web', 'signature_text',
            ]));
            $this->assertTrue(Schema::connection($connection)->hasColumn('invoice_reminders', 'body_html'));
            $this->assertTrue(Schema::connection($connection)->hasColumn('invoice_paid_notifications', 'body_html'));
            $this->assertTrue(Schema::connection($connection)->hasColumns('invoice_automation_settings', [
                'partial_subject', 'partial_body', 'overpaid_subject', 'overpaid_body',
            ]));
        }
        $this->assertFalse(Schema::connection('central')->hasTable('invoice_email_settings'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'sender_name' => 'Fakturace První subjekt',
            'reply_to' => 'reply@example.test',
            'pdf_style' => 'classic',
            'subject_template' => 'Doklad {invoice_number} / {customer_name}',
            'body_template' => "Dodavatel: {supplier_name}\nČástka: {amount}\nSplatnost: {due_date}\n{web_invoice_url}",
            'signature' => 'Podpis {supplier_name}',
            'attach_pdf' => true,
            'include_web_invoice' => true,
            ...$overrides,
        ];
    }

    private function png(string $name, int $kilobytes = 1): UploadedFile
    {
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $content = $pixel.str_repeat("\0", max(0, ($kilobytes * 1024) - strlen($pixel)));

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
