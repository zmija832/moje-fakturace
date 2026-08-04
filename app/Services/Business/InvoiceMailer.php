<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoiceDeliveryIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoiceDocumentNotFound;
use App\Domain\Invoices\Exceptions\InvoiceEmailRecipientMissing;
use App\Domain\Invoices\Exceptions\InvoiceEmailSendFailed;
use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceEmailDeliveryStatus;
use App\Enums\InvoiceStatus;
use App\Mail\InvoiceIssuedMail;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceEmailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class InvoiceMailer
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceReader $reader,
        private readonly InvoicePdfGenerator $pdfGenerator,
        private readonly InvoiceDocumentViewModelFactory $viewModels,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $input */
    public function send(string $invoiceUuid, string $correlationUuid, array $input): InvoiceEmailDelivery
    {
        if (! Str::isUuid($invoiceUuid) || ! Str::isUuid($correlationUuid)) {
            throw InvoiceDeliveryIdempotencyConflict::create();
        }
        $connection = $this->connectionResolver->resolve()->connectionName();
        $invoice = $this->reader->find($invoiceUuid);
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw InvoiceNotIssuedForDelivery::create();
        }
        $existing = InvoiceEmailDelivery::query()->where('send_correlation_uuid', $correlationUuid)->first();
        if ($existing !== null) {
            if ((int) $existing->invoice_id !== (int) $invoice->id) {
                throw InvoiceDeliveryIdempotencyConflict::create();
            }

            return $existing;
        }

        $document = $this->pdfGenerator->generate($invoice->uuid, (string) Str::uuid());
        if (! Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path)) {
            throw InvoiceDocumentNotFound::create();
        }
        $model = $this->viewModels->make($invoice)->toArray();
        $recipientEmail = trim((string) ($input['recipient_email'] ?? $invoice->issuedRevision->customerSnapshot->email));
        if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw InvoiceEmailRecipientMissing::create();
        }
        $recipientName = trim((string) ($input['recipient_name'] ?? $invoice->issuedRevision->customerSnapshot->display_name));
        $subject = trim((string) ($input['subject'] ?? ''));
        $subject = $subject !== '' ? $subject : 'Faktura '.$invoice->document_number.' od '.$model['supplier']['name'];
        $message = trim((string) ($input['message'] ?? ''));
        [$bodyText, $bodyHtml] = $this->bodies($model, $recipientName, $message);

        [$delivery, $created] = DB::connection($connection)->transaction(function () use ($invoice, $document, $correlationUuid, $recipientEmail, $recipientName, $subject, $bodyText, $bodyHtml): array {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InvoiceStatus::Issued) {
                throw InvoiceNotIssuedForDelivery::create();
            }
            $existing = InvoiceEmailDelivery::query()->where('send_correlation_uuid', $correlationUuid)->first();
            if ($existing !== null) {
                if ((int) $existing->invoice_id !== (int) $locked->id) {
                    throw InvoiceDeliveryIdempotencyConflict::create();
                }

                return [$existing, false];
            }
            $delivery = new InvoiceEmailDelivery;
            $delivery->forceFill([
                'invoice_id' => $locked->id,
                'invoice_document_id' => $document->id,
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName !== '' ? $recipientName : null,
                'subject' => $subject,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'status' => InvoiceEmailDeliveryStatus::Pending->value,
                'send_correlation_uuid' => $correlationUuid,
                'attempted_at' => now(),
                'created_by_actor' => $this->actor(),
            ])->save();
            $delivery->setRelation('invoice', $locked)->setRelation('document', $document);
            $this->auditWriter->write(
                BusinessAuditEvent::InvoiceEmailSendRequested,
                BusinessAuditableType::InvoiceEmailDelivery,
                $delivery->uuid,
                null,
                $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailDelivery, $delivery),
                ['requested'],
                BusinessAuditableType::Invoice,
                $locked->uuid,
            );

            return [$delivery, true];
        }, 3);

        if (! $created) {
            return $delivery;
        }

        try {
            $sentMessage = Mail::to($delivery->recipient_email, $delivery->recipient_name)
                ->send(new InvoiceIssuedMail($recipientName, $subject, $bodyText, $bodyHtml, $document));

            return DB::connection($connection)->transaction(function () use ($delivery, $sentMessage): InvoiceEmailDelivery {
                $locked = InvoiceEmailDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill([
                    'status' => InvoiceEmailDeliveryStatus::Sent->value,
                    'provider_message_id' => $sentMessage?->getMessageId(),
                    'sent_at' => now(),
                ])->save();
                $locked->loadMissing(['invoice', 'document']);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceEmailSent,
                    BusinessAuditableType::InvoiceEmailDelivery,
                    $locked->uuid,
                    null,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailDelivery, $locked),
                    ['status', 'sent_at'],
                    BusinessAuditableType::Invoice,
                    $locked->invoice->uuid,
                );

                return $locked;
            }, 3);
        } catch (Throwable) {
            DB::connection($connection)->transaction(function () use ($delivery): void {
                $locked = InvoiceEmailDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== InvoiceEmailDeliveryStatus::Pending) {
                    return;
                }
                $locked->forceFill([
                    'status' => InvoiceEmailDeliveryStatus::Failed->value,
                    'failed_at' => now(),
                    'failure_code' => 'transport_error',
                    'failure_message' => 'Poštovní transport zprávu nepotvrdil. Zkontrolujte SMTP konfiguraci a zkuste novou operaci.',
                ])->save();
                $locked->loadMissing(['invoice', 'document']);
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceEmailFailed,
                    BusinessAuditableType::InvoiceEmailDelivery,
                    $locked->uuid,
                    null,
                    $this->auditSanitizer->snapshot(BusinessAuditableType::InvoiceEmailDelivery, $locked),
                    ['status', 'failed_at', 'failure_code'],
                    BusinessAuditableType::Invoice,
                    $locked->invoice->uuid,
                );
            }, 3);
            throw InvoiceEmailSendFailed::create();
        }
    }

    private function bodies(array $model, string $recipientName, string $message): array
    {
        $greeting = $recipientName !== '' ? 'Dobrý den, '.$recipientName.',' : 'Dobrý den,';
        $lines = [$greeting, '', 'zasíláme fakturu '.$model['document_number'].' od '.$model['supplier']['name'].'.', 'Částka: '.$model['totals']['grand_total'].' '.$model['currency'], 'Splatnost: '.$model['due_on']];
        if ($message !== '') {
            $lines[] = '';
            $lines[] = $message;
        }
        $lines[] = '';
        $lines[] = 'PDF faktury je přiloženo k této zprávě.';
        $lines[] = '';
        $lines[] = 'S pozdravem';
        $lines[] = $model['supplier']['name'];
        $text = implode("\n", $lines);

        return [$text, '<div style="font-family:Arial,sans-serif;line-height:1.5">'.nl2br(e($text)).'</div>'];
    }

    private function actor(): ?string
    {
        $user = auth()->user();

        return $user ? 'central-user:'.$user->getAuthIdentifier() : null;
    }
}
