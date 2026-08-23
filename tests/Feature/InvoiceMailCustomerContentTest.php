<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Services\Business\AutomationTemplateRenderer;
use App\Services\Business\InvoiceAutomationSettingsService;
use App\Services\Business\InvoiceEmailSettingsService;
use App\Services\Business\InvoiceMailDesignRenderer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceMailCustomerContentTest extends TestCase
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

    public function test_partial_paid_and_overpaid_customer_emails_contain_authoritative_facts_and_web_invoice(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(InvoiceEmailSettingsService::class)->save([
            'sender_name' => 'Dodavatel', 'reply_to' => null, 'template_style' => 'modern',
            'subject_template' => 'Faktura {invoice_number}', 'body_template' => 'Dobrý den.',
            'signature' => null, 'signature_name' => 'Milan Zítek', 'signature_text' => 'S pozdravem',
            'attach_pdf' => true, 'include_web_invoice' => true,
        ]);
        $renderer = app(AutomationTemplateRenderer::class);

        $partial = $renderer->paymentNotification(
            $invoice,
            $this->event($invoice->uuid, 'partial-payment', '40', 'partially_paid', '40', '60'),
            'customer',
            'Vlastní částečná úhrada {invoice_number}',
            'Vlastní úvod částečné úhrady pro {customer_name}.',
        );
        $this->assertSame('Vlastní částečná úhrada '.$invoice->document_number, $partial['subject']);
        $this->assertStringContainsString('Vlastní úvod částečné úhrady', $partial['body']);
        $this->assertStringContainsString('Částečně uhrazeno', $partial['body_html']);
        $this->assertStringContainsString('Přijatá platba: 40 Kč', $partial['body']);
        $this->assertStringContainsString('Celkem uhrazeno: 40 Kč', $partial['body']);
        $this->assertStringContainsString('Zbývá uhradit: 60 Kč', $partial['body']);
        $this->assertStringContainsString('Faktura celkem: 100 Kč', $partial['body']);
        $this->assertStringContainsString('Datum platby: 21. 8. 2026', $partial['body']);
        $this->assertStringContainsString('Variabilní symbol: 20260001', $partial['body']);
        $this->assertStringContainsString((string) $partial['web_invoice_url'], $partial['body']);
        $this->assertCustomerWebInvoiceOnly($partial);

        $paid = $renderer->paymentNotification(
            $invoice,
            $this->event($invoice->uuid, 'paid-payment', '60', 'paid', '100', '0'),
            'customer',
            'Vlastní potvrzení {invoice_number}',
            'Vlastní bezpečný text pro {customer_name}.',
        );
        $this->assertSame('Vlastní potvrzení '.$invoice->document_number, $paid['subject']);
        $this->assertStringContainsString('Vlastní bezpečný text', $paid['body']);
        $this->assertStringContainsString('Uhrazeno', $paid['body_html']);
        $this->assertStringContainsString('Přijatá platba: 60 Kč', $paid['body']);
        $this->assertStringContainsString('Celkem uhrazeno: 100 Kč', $paid['body']);
        $this->assertCustomerWebInvoiceOnly($paid);

        $overpaid = $renderer->paymentNotification(
            $invoice,
            $this->event($invoice->uuid, 'over-payment', '110', 'overpaid', '110', '-10'),
            'customer',
            'Vlastní přeplatek {invoice_number}',
            'Vlastní úvod přeplatku ve výši {remaining}.',
        );
        $this->assertSame('Vlastní přeplatek '.$invoice->document_number, $overpaid['subject']);
        $this->assertStringContainsString('Vlastní úvod přeplatku', $overpaid['body']);
        $this->assertStringContainsString('Přeplaceno', $overpaid['body_html']);
        $this->assertStringContainsString('Přeplatek: 10 Kč', $overpaid['body']);
        $this->assertStringContainsString('Celkem uhrazeno: 110 Kč', $overpaid['body']);
        $this->assertCustomerWebInvoiceOnly($overpaid);
    }

    public function test_reminder_uses_shared_layout_plain_text_fallback_and_public_link(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();

        $rendered = app(AutomationTemplateRenderer::class)->reminder(
            $invoice,
            'Upomínka {invoice_number}',
            'Prosíme o úhradu {remaining_amount}. Faktura je {days_overdue} dní po splatnosti.',
            '60',
            5,
        );

        $this->assertStringContainsString('Upomínka k faktuře '.$invoice->document_number, $rendered['body_html']);
        $this->assertStringContainsString('Původní částka: 100 Kč', $rendered['body']);
        $this->assertStringContainsString('Uhrazeno: 40 Kč', $rendered['body']);
        $this->assertStringContainsString('Zbývá uhradit: 60 Kč', $rendered['body']);
        $this->assertStringContainsString('Splatnost: 16. 8. 2026', $rendered['body']);
        $this->assertStringContainsString($rendered['web_invoice_url'], $rendered['body']);
        $this->assertStringContainsString('Webfaktura:', $rendered['body']);
        $this->assertCustomerWebInvoiceOnly($rendered);
    }

    public function test_admin_can_edit_safe_customer_payment_texts_for_all_payment_states(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        $defaults = app(InvoiceAutomationSettingsService::class)->defaults();

        $this->actingAs($admin)
            ->withSession($this->deliveryBusinessSession($business))
            ->get(route('automation-settings.edit'))
            ->assertOk()
            ->assertSee('name="partial_subject"', false)
            ->assertSee('name="paid_subject"', false)
            ->assertSee('name="overpaid_subject"', false);

        $response = $this->actingAs($admin)
            ->withSession($this->deliveryBusinessSession($business))
            ->put(route('automation-settings.update'), [
                ...$defaults,
                'partial_body' => 'Přijato {payment_amount}, zbývá {remaining}.',
                'paid_body' => 'Uhrazeno {paid_total} dne {payment_date}.',
                'overpaid_body' => 'Přeplatek {overpayment}.',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('invoice_automation_settings', [
            'singleton_key' => '1',
            'partial_body' => 'Přijato {payment_amount}, zbývá {remaining}.',
            'paid_body' => 'Uhrazeno {paid_total} dne {payment_date}.',
            'overpaid_body' => 'Přeplatek {overpayment}.',
        ], 'business_1');
    }

    private function event(string $invoiceUuid, string $paymentUuid, string $amount, string $status, string $paid, string $remaining): InvoicePaymentEventSnapshot
    {
        return new InvoicePaymentEventSnapshot(
            invoiceUuid: $invoiceUuid,
            documentNumber: 'FV-2026-00001',
            paymentUuid: $paymentUuid,
            paymentType: 'payment',
            amount: $amount,
            currency: 'CZK',
            paidOn: '2026-08-21',
            statusBefore: 'unpaid',
            statusAfter: $status,
            paidTotal: $paid,
            remainingTotal: $remaining,
            notificationIntents: [],
        );
    }

    /** @param array{body:string,body_html:string,web_invoice_url:?string} $rendered */
    private function assertCustomerWebInvoiceOnly(array $rendered): void
    {
        $url = (string) $rendered['web_invoice_url'];
        $this->assertNotSame('', $url);
        $this->assertStringContainsString('href="'.$url.'"', $rendered['body_html']);
        $this->assertStringContainsString('tohoto odkazu', $rendered['body_html']);
        $this->assertStringNotContainsString('>'.$url.'</a>', $rendered['body_html']);
        $this->assertStringNotContainsString('Stáhnout PDF', $rendered['body_html']);
        $this->assertStringContainsString($url, $rendered['body']);
        $this->assertSame(1, substr_count($rendered['body'], $url));
    }
}
