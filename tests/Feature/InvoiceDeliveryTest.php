<?php

namespace Tests\Feature;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\Invoices\Exceptions\InvoiceEmailSendFailed;
use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Domain\Invoices\Exceptions\InvoicePdfGenerationFailed;
use App\Enums\BusinessConnection;
use App\Mail\InvoiceIssuedMail;
use App\Models\Business\CompanySetting;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoiceEmailDelivery;
use App\Models\User;
use App\Services\Business\BusinessAuditWriter;
use App\Services\Business\InvoiceDocumentViewModelFactory;
use App\Services\Business\InvoiceMailer;
use App\Services\Business\InvoicePdfGenerator;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesInvoiceDeliveryFixtures;
use Tests\Concerns\InteractsWithBusinessDatabases;
use Tests\TestCase;

class InvoiceDeliveryTest extends TestCase
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

    public function test_pdf_is_private_immutable_idempotent_and_snapshot_only(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, $client, $account, $rate] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $before = app(InvoiceDocumentViewModelFactory::class)->make($invoice)->toArray();
        $this->assertTrue($before['is_non_payer']);
        $print = view('business.invoices.print', ['document' => $before, 'archival' => true])->render();
        $this->assertStringContainsString('Neplátce DPH', $print);
        $this->assertStringContainsString('Jednotková cena', $print);
        $this->assertSame(1, substr_count($print, 'Neplátce DPH'));
        $this->assertStringNotContainsString('Souhrn DPH', $print);
        $this->assertStringNotContainsString('Nulová sazba', $print);
        $this->assertStringNotContainsString('Osvobozené plnění', $print);
        $this->assertStringNotContainsString('Mimo předmět DPH', $print);
        $payerDocument = $before;
        $payerDocument['is_non_payer'] = false;
        $payerPrint = view('business.invoices.print', ['document' => $payerDocument, 'archival' => true])->render();
        $this->assertStringContainsString('Souhrn DPH', $payerPrint);
        $this->assertStringContainsString('Základ daně', $payerPrint);
        $correlation = (string) Str::uuid();
        $first = app(InvoicePdfGenerator::class)->generate($invoice->uuid, $correlation);
        $repeated = app(InvoicePdfGenerator::class)->generate($invoice->uuid, $correlation);

        $this->assertSame($first->id, $repeated->id);
        $this->assertSame(1, InvoiceDocument::query()->count());
        Storage::disk('invoice_documents')->assertExists($first->storage_path);
        $bytes = Storage::disk('invoice_documents')->get($first->storage_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $first->sha256);
        $this->assertSame(strlen($bytes), $first->size_bytes);
        $this->assertSame('faktura-FV-202600001.pdf', $first->original_filename);
        $this->assertStringNotContainsString($client->display_name, $first->storage_path);
        try {
            DB::connection('business_1')->table('invoice_documents')->where('id', $first->id)->update(['original_filename' => 'prepsano.pdf']);
            $this->fail('PDF metadata musí být immutable i při přímém SQL update.');
        } catch (QueryException) {
            $this->assertSame($first->original_filename, $first->fresh()->original_filename);
        }

        $client->forceFill(['display_name' => 'Živý klient změněn', 'email' => 'live@example.test'])->save();
        $account->forceFill(['name' => 'Živý účet změněn'])->save();
        $rate->forceFill(['name' => 'Živá sazba změněna'])->save();
        CompanySetting::query()->update(['legal_name' => 'Živá firma změněna']);
        $after = app(InvoiceDocumentViewModelFactory::class)->make($invoice->fresh()->load('issuedRevision'))->toArray();
        $this->assertSame($before, $after);

        $second = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid(), true);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, InvoiceDocument::query()->count());
        Storage::disk('invoice_documents')->assertExists($first->storage_path);
        $this->assertSame(2, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.pdf_generated')->count());
        $pdfAudit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.pdf_generated')->oldest('id')->first();
        $this->assertSame('central-user:'.$admin->id, $pdfAudit->actor_user_uuid);
        $this->assertStringNotContainsString($first->storage_path, (string) $pdfAudit->new_values);
    }

    public function test_draft_cannot_generate_pdf(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$issued] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);

        $this->expectException(InvoiceNotIssuedForDelivery::class);
        app(InvoicePdfGenerator::class)->generate($issued->uuid, (string) Str::uuid());
    }

    public function test_draft_cannot_be_sent_and_creates_no_delivery_history(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        $this->actingAs($admin);

        try {
            app(InvoiceMailer::class)->send($draft->uuid, (string) Str::uuid(), [
                'recipient_email' => 'recipient@example.test',
            ]);
            $this->fail('Návrh faktury nesmí být odeslán.');
        } catch (InvoiceNotIssuedForDelivery) {
            $this->assertSame(0, InvoiceEmailDelivery::query()->count());
            $this->assertSame(0, InvoiceDocument::query()->count());
        }
    }

    public function test_transport_failure_is_sanitized_and_audited(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        Mail::shouldReceive('to')->once()->andReturnUsing(function (): never {
            $this->assertSame('pending', InvoiceEmailDelivery::query()->sole()->status->value);
            throw new \RuntimeException('smtp-password=super-secret');
        });

        try {
            app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
            $this->fail('Očekávána bezpečná doménová chyba odeslání.');
        } catch (InvoiceEmailSendFailed) {
        }

        $delivery = InvoiceEmailDelivery::query()->firstOrFail();
        $this->assertSame('failed', $delivery->status->value);
        $this->assertStringNotContainsString('super-secret', $delivery->failure_message);
        $auditJson = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_failed')->value('new_values');
        $this->assertStringNotContainsString('super-secret', (string) $auditJson);
        $this->assertStringNotContainsString($delivery->body_text, (string) $auditJson);
    }

    public function test_pdf_audit_failure_rolls_back_metadata_and_removes_only_new_files(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $existing = app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid());
        $writer = Mockery::mock(BusinessAuditWriter::class);
        $writer->shouldReceive('write')->andThrow(new \RuntimeException('audit unavailable'));
        app()->instance(BusinessAuditWriter::class, $writer);

        try {
            app(InvoicePdfGenerator::class)->generate($invoice->uuid, (string) Str::uuid(), true);
            $this->fail('Selhání auditu musí zrušit nové PDF metadata.');
        } catch (InvoicePdfGenerationFailed) {
            $this->assertSame(1, InvoiceDocument::query()->count());
            Storage::disk('invoice_documents')->assertExists($existing->storage_path);
            $this->assertSame([], Storage::disk('invoice_documents')->files('tmp'));
        }
    }

    public function test_production_log_transport_fails_closed_without_false_success_flash(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $originalEnvironment = app()->environment();
        config(['mail.default' => 'log']);
        app()->instance('env', 'production');

        try {
            app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), [
                'recipient_email' => 'snapshot@example.test',
            ]);
            $this->fail('Log transport nesmí v produkci vytvořit falešně úspěšné doručení.');
        } catch (InvoiceEmailSendFailed) {
            $this->assertTrue(true);
        } finally {
            app()->instance('env', $originalEnvironment);
        }

        $this->assertSame('failed', InvoiceEmailDelivery::query()->sole()->status->value);
        Mail::assertNothingSent();
    }

    public function test_mail_uses_snapshot_attaches_pdf_and_is_idempotent(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, $client] = $this->createIssuedInvoice();
        $this->actingAs($admin);
        $client->forceFill(['email' => 'live@example.test'])->save();
        $correlation = (string) Str::uuid();
        $delivery = app(InvoiceMailer::class)->send($invoice->uuid, $correlation, []);
        $repeated = app(InvoiceMailer::class)->send($invoice->uuid, $correlation, ['recipient_email' => 'other@example.test']);

        $this->assertSame($delivery->id, $repeated->id);
        $this->assertSame('sent', $delivery->status->value);
        $this->assertSame('snapshot@example.test', $delivery->recipient_email);
        $this->assertSame(1, InvoiceEmailDelivery::query()->count());
        $this->assertStringContainsString($invoice->document_number, $delivery->subject);
        $this->assertStringContainsString('PDF faktury', $delivery->body_text);
        Mail::assertSent(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail) use ($delivery): bool {
            $document = $delivery->document;
            $expected = Attachment::fromStorageDisk($document->storage_disk, $document->storage_path)
                ->as($document->original_filename)
                ->withMime($document->mime_type);

            return $mail->hasAttachment($expected);
        });
        $this->assertSame(1, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_sent')->count());
        $mailAudit = DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_sent')->first();
        $mailAuditValues = json_decode((string) $mailAudit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('central-user:'.$admin->id, $mailAudit->actor_user_uuid);
        $this->assertSame('s•••@example.test', $mailAuditValues['recipient_email_masked']);
        $this->assertStringNotContainsString($delivery->recipient_email, (string) $mailAudit->new_values);
        $this->assertStringNotContainsString($delivery->body_text, (string) $mailAudit->new_values);
        $this->assertStringNotContainsString($delivery->document->storage_path, (string) $mailAudit->new_values);
        try {
            DB::connection('business_1')->table('invoice_email_deliveries')->where('id', $delivery->id)->update(['recipient_email' => 'changed@example.test']);
            $this->fail('Obsah delivery musí být immutable i při přímém SQL update.');
        } catch (QueryException) {
            $this->assertSame('snapshot@example.test', $delivery->fresh()->recipient_email);
        }
        $this->assertSame('live@example.test', $client->fresh()->email);
        $newAttempt = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), []);
        $this->assertNotSame($delivery->id, $newAttempt->id);
        $this->assertSame(2, InvoiceEmailDelivery::query()->count());
        Mail::assertSent(InvoiceIssuedMail::class, 2);
        $this->assertSame(2, DB::connection('business_1')->table('audit_logs')->where('event', 'invoice.email_sent')->count());
    }

    public function test_admin_recipient_override_is_snapshotted_without_changing_client(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice, $client] = $this->createIssuedInvoice();
        $this->actingAs($admin);

        $delivery = app(InvoiceMailer::class)->send($invoice->uuid, (string) Str::uuid(), [
            'recipient_email' => 'override@example.test',
            'recipient_name' => 'Jednorázový příjemce',
            'subject' => 'Vlastní bezpečný předmět',
            'message' => '<script>alert(1)</script>',
        ]);

        $this->assertSame('override@example.test', $delivery->recipient_email);
        $this->assertSame('snapshot@example.test', $client->fresh()->email);
        $this->assertSame('Vlastní bezpečný předmět', $delivery->subject);
        $this->assertStringNotContainsString('<script>', (string) $delivery->body_html);
        $this->assertStringContainsString('&lt;script&gt;', (string) $delivery->body_html);
        Mail::assertSent(InvoiceIssuedMail::class, 1);
    }

    public function test_http_authorization_download_preview_and_history_are_safe(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        Mail::fake();
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice();
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.print', $invoice->uuid))
            ->assertOk()
            ->assertSee('Tiskový náhled')
            ->assertSee('Žluťoučký dodavatel')
            ->assertSee('Bezpečná služba')
            ->assertSee('@media print', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('business_1');
        $this->get(route('invoices.print', $invoice->uuid).'?connection=business_2')
            ->assertOk()
            ->assertSee('Žluťoučký dodavatel')
            ->assertDontSee('business_2');
        $this->post(route('invoices.pdf.generate', $invoice->uuid), [
            'generation_correlation_uuid' => (string) Str::uuid(),
            'storage_path' => '../../public/cizi.pdf',
            'connection' => 'business_2',
            'qr_payload' => 'SPD*1.0*ACC:ATTACKER',
        ])->assertSessionHasErrors(['storage_path', 'connection', 'qr_payload']);
        $this->post(route('invoices.pdf.generate', $invoice->uuid), [
            'generation_correlation_uuid' => (string) Str::uuid(),
        ])->assertRedirect(route('invoices.show', $invoice->uuid));
        $download = $this->get(route('invoices.pdf.download', $invoice->uuid));
        $download->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('faktura-FV-202600001.pdf', (string) $download->headers->get('content-disposition'));
        $this->get(route('invoices.pdf.download-version', [$invoice->uuid, (string) Str::uuid()]))->assertNotFound();
        $this->get(route('invoices.email.form', $invoice->uuid))->assertOk()->assertSee('snapshot@example.test')->assertSee('_token', false)->assertDontSee('storage_path')->assertDontSee('MAIL_PASSWORD');
        $this->post(route('invoices.email.send', $invoice->uuid), [
            'send_correlation_uuid' => (string) Str::uuid(),
            'recipient_email' => 'snapshot@example.test',
            'provider_message_id' => 'podvržený',
        ])->assertSessionHasErrors('provider_message_id');
        $this->assertSame(0, InvoiceEmailDelivery::query()->count());
        $this->post(route('invoices.email.send', $invoice->uuid), [
            'send_correlation_uuid' => (string) Str::uuid(),
            'recipient_email' => 'snapshot@example.test',
        ])->assertRedirect(route('invoices.show', $invoice->uuid));
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('snapshot@example.test')->assertSee('Přijato poštovním serverem')->assertDontSee('storage_path')->assertDontSee('MAIL_PASSWORD');

        [$viewer] = $this->deliveryMembership('viewer', BusinessConnection::Business1, $business);
        $this->actingAs($viewer)->withSession($this->deliveryBusinessSession($business));
        $this->get(route('invoices.show', $invoice->uuid))->assertOk()->assertSee('snapshot@example.test')->assertDontSee('Vytvořit novou verzi PDF')->assertDontSee('Odeslat e-mailem');
        $this->get(route('invoices.print', $invoice->uuid))->assertOk();
        $this->get(route('invoices.pdf.download', $invoice->uuid))->assertOk();
        $this->post(route('invoices.pdf.generate', $invoice->uuid), ['generation_correlation_uuid' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('invoices.email.form', $invoice->uuid))->assertForbidden();
        $this->post(route('invoices.email.send', $invoice->uuid), [
            'send_correlation_uuid' => (string) Str::uuid(),
            'recipient_email' => 'viewer@example.test',
        ])->assertForbidden();
    }

    public function test_draft_http_detail_has_no_document_or_email_workflow(): void
    {
        [$admin, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$draft] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($admin)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.show', $draft->uuid))->assertOk()->assertDontSee('Dokumenty')->assertDontSee('Odeslat e-mailem');
        $this->get(route('invoices.print', $draft->uuid))->assertForbidden();
        $this->get(route('invoices.pdf.download', $draft->uuid))->assertForbidden();
        $this->get(route('invoices.email.form', $draft->uuid))->assertForbidden();
        $this->post(route('invoices.pdf.generate', $draft->uuid), [
            'generation_correlation_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->post(route('invoices.email.send', $draft->uuid), [
            'send_correlation_uuid' => (string) Str::uuid(),
            'recipient_email' => 'draft@example.test',
        ])->assertForbidden();
        $this->assertSame(0, InvoiceDocument::query()->count());
        $this->assertSame(0, InvoiceEmailDelivery::query()->count());
    }

    public function test_invoice_uuid_from_another_business_is_not_found(): void
    {
        [$firstAdmin, $firstBusiness] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($firstBusiness);
        [$firstInvoice] = $this->createIssuedInvoice(false);
        [$secondAdmin, $secondBusiness] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($secondAdmin)->withSession($this->deliveryBusinessSession($secondBusiness));

        $this->get(route('invoices.print', $firstInvoice->uuid))->assertNotFound();
        $this->get(route('invoices.pdf.download', $firstInvoice->uuid))->assertNotFound();
        $this->get(route('invoices.email.form', $firstInvoice->uuid))->assertNotFound();
        $this->post(route('invoices.duplicate', $firstInvoice->uuid))->assertNotFound();
        $this->assertNotSame($firstAdmin->id, $secondAdmin->id);
    }

    public function test_user_without_business_access_is_forbidden_on_delivery_routes(): void
    {
        [, $business] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($business);
        [$invoice] = $this->createIssuedInvoice(false);
        app(ActiveBusinessContext::class)->clear();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->withSession($this->deliveryBusinessSession($business));

        $this->get(route('invoices.print', $invoice->uuid))->assertForbidden();
        $this->get(route('invoices.pdf.download', $invoice->uuid))->assertForbidden();
        $this->get(route('invoices.email.form', $invoice->uuid))->assertForbidden();
    }

    public function test_delivery_correlations_are_tenant_local_and_paths_are_physically_namespaced(): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('PDF/QR knihovny nejsou nainstalované ve vendor.');
        }
        Mail::fake();
        $correlation = (string) Str::uuid();
        $sendCorrelation = (string) Str::uuid();
        [$firstAdmin, $firstBusiness] = $this->deliveryMembership();
        app(ActiveBusinessContext::class)->set($firstBusiness);
        [$firstInvoice] = $this->createIssuedInvoice();
        $this->actingAs($firstAdmin);
        $firstDocument = app(InvoicePdfGenerator::class)->generate($firstInvoice->uuid, $correlation);
        $firstDelivery = app(InvoiceMailer::class)->send($firstInvoice->uuid, $sendCorrelation, []);

        [$secondAdmin, $secondBusiness] = $this->deliveryMembership('admin', BusinessConnection::Business2);
        app(ActiveBusinessContext::class)->set($secondBusiness);
        [$secondInvoice] = $this->createIssuedInvoice();
        $this->actingAs($secondAdmin);
        $secondDocument = app(InvoicePdfGenerator::class)->generate($secondInvoice->uuid, $correlation);
        $secondDelivery = app(InvoiceMailer::class)->send($secondInvoice->uuid, $sendCorrelation, []);

        $this->assertSame(1, DB::connection('business_1')->table('invoice_documents')->where('generation_correlation_uuid', $correlation)->count());
        $this->assertSame(1, DB::connection('business_2')->table('invoice_documents')->where('generation_correlation_uuid', $correlation)->count());
        $this->assertSame(1, DB::connection('business_1')->table('invoice_email_deliveries')->where('send_correlation_uuid', $sendCorrelation)->count());
        $this->assertSame(1, DB::connection('business_2')->table('invoice_email_deliveries')->where('send_correlation_uuid', $sendCorrelation)->count());
        $this->assertSame('sent', $firstDelivery->status->value);
        $this->assertSame('sent', $secondDelivery->status->value);
        $this->assertStringStartsWith($firstBusiness->uuid.'/', $firstDocument->storage_path);
        $this->assertStringStartsWith($secondBusiness->uuid.'/', $secondDocument->storage_path);
        $this->assertNotSame($firstDocument->storage_path, $secondDocument->storage_path);
        app(ActiveBusinessContext::class)->clear();
        $this->actingAs($secondAdmin)->withSession($this->deliveryBusinessSession($secondBusiness));
        $this->get(route('invoices.pdf.download-version', [$secondInvoice->uuid, $firstDocument->uuid]))->assertNotFound();
    }
}
