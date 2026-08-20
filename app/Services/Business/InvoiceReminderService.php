<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoicePaymentSummary;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceReminderOrigin;
use App\Enums\InvoiceStatus;
use App\Mail\AutomationMail;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceDocument;
use App\Models\Business\InvoiceReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceReminderService
{
    private const CLAIM_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly InvoiceAutomationSettingsService $settings,
        private readonly InvoicePaymentReader $payments,
        private readonly AutomationTemplateRenderer $renderer,
        private readonly InvoiceEmailSettingsService $emailSettings,
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessDate $businessDate,
        private readonly BusinessAuditWriter $auditWriter,
        private readonly InvoicePdfGenerator $pdfGenerator,
    ) {}

    /** @return array{processed:int,failed:int} */
    public function runDue(CarbonImmutable $today, int $limit = 50): array
    {
        $today = $this->businessDate->normalize($today);
        $settings = $this->settings->current();
        $result = ['processed' => 0, 'failed' => 0];
        if (! $settings->reminders_enabled) {
            return $result;
        }

        $invoices = Invoice::query()
            ->with(['issuedRevision.customerSnapshot', 'issuedRevision.supplierSnapshot', 'payments', 'reminderOverride'])
            ->where('status', InvoiceStatus::Issued->value)
            ->whereNull('archived_at')
            ->whereDate('due_on', '<', $today->format('Y-m-d'))
            ->limit($limit)
            ->get();

        foreach ($invoices as $invoice) {
            if ($invoice->reminderOverride?->disabled) {
                continue;
            }
            $summary = $this->payments->summary($invoice);
            if (! in_array($summary->status, [InvoicePaymentStatus::Unpaid, InvoicePaymentStatus::PartiallyPaid], true)) {
                continue;
            }

            $daysOverdue = $this->businessDate->daysBetween($invoice->due_on, $today);
            foreach ([1, 2, 3] as $level) {
                $configuredDay = $settings->{"reminder_day_{$level}"};
                if ($configuredDay === null || (int) $configuredDay > $daysOverdue) {
                    continue;
                }

                $existing = InvoiceReminder::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('level', $level)
                    ->first();

                if ($existing?->status === 'sent') {
                    continue;
                }
                if ($existing?->status === 'prepared' && $settings->reminder_mode === 'prepare') {
                    continue;
                }
                if ($existing !== null && $settings->reminder_mode !== 'send') {
                    break;
                }

                try {
                    if ($existing !== null) {
                        $attempts = $existing->send_attempts;
                        $after = $this->send($existing, InvoiceReminderOrigin::Automatic);
                        if ($after->send_attempts > $attempts) {
                            $result['processed']++;
                        }
                    } else {
                        $scheduledOn = $this->businessDate->addDays($invoice->due_on, (int) $configuredDay);
                        $this->prepare(
                            $invoice,
                            $level,
                            $scheduledOn,
                            $settings->reminder_mode === 'send',
                            InvoiceReminderOrigin::Automatic,
                        );
                        $result['processed']++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $result['failed']++;
                }

                // Catch-up je záměrně sekvenční: jeden stupeň na fakturu a jeden běh.
                break;
            }
        }

        return $result;
    }

    public function prepare(
        Invoice $invoice,
        int $level,
        CarbonImmutable $scheduledOn,
        bool $send,
        InvoiceReminderOrigin $origin = InvoiceReminderOrigin::Automatic,
    ): InvoiceReminder {
        $scheduledOn = $this->businessDate->normalize($scheduledOn);
        $connection = $this->connectionResolver->resolve()->connectionName();
        $reminder = DB::connection($connection)->transaction(function () use ($invoice, $level, $scheduledOn, $origin): InvoiceReminder {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if ($lockedInvoice === null) {
                throw ValidationException::withMessages(['invoice' => 'Faktura již neexistuje.']);
            }
            $lockedInvoice->load([
                'issuedRevision.customerSnapshot',
                'issuedRevision.supplierSnapshot',
                'payments',
                'reminderOverride',
            ]);
            $summary = $this->assertEligible($lockedInvoice, $origin);
            $existing = InvoiceReminder::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('level', $level)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $setting = $this->settings->current();
            $rendered = $this->renderer->reminder(
                $lockedInvoice,
                $setting->{"reminder_subject_{$level}"},
                $setting->{"reminder_body_{$level}"},
                $summary->remainingTotal,
                $this->businessDate->daysBetween($lockedInvoice->due_on, $scheduledOn),
            );
            $reminder = new InvoiceReminder;
            $reminder->forceFill([
                'invoice_id' => $lockedInvoice->id,
                'level' => $level,
                'scheduled_on' => $scheduledOn,
                'status' => 'prepared',
                'recipient_email' => $rendered['recipient'],
                'subject' => $rendered['subject'],
                'body_text' => $rendered['body'],
                'correlation_uuid' => (string) Str::uuid(),
            ]);
            $reminder->save();

            return $reminder;
        }, 3);

        return $send ? $this->send($reminder, $origin) : $reminder->refresh();
    }

    public function send(
        InvoiceReminder $reminder,
        InvoiceReminderOrigin $origin = InvoiceReminderOrigin::Automatic,
    ): InvoiceReminder {
        $reminder = $reminder->refresh();
        if ($reminder->status === 'sent') {
            return $reminder;
        }

        [$document, $webInvoiceUrl] = $this->deliveryAssets($reminder, $origin);
        $claim = $this->claim($reminder, $origin);
        if ($claim === null) {
            return $reminder->refresh();
        }

        [$claimed, $token] = $claim;
        if (! filter_var($claimed->recipient_email, FILTER_VALIDATE_EMAIL)) {
            return $this->finishClaim($claimed, $token, 'failed', $origin, 'recipient_missing', 'Klient nemá platnou e-mailovou adresu.');
        }

        try {
            $email = $this->emailSettings->current();
            Mail::to($claimed->recipient_email)->send(new AutomationMail(
                $claimed->subject,
                $claimed->body_text,
                $email->sender_name,
                $email->reply_to,
                $document,
                $webInvoiceUrl,
            ));

            return $this->finishClaim($claimed, $token, 'sent', $origin);
        } catch (Throwable $exception) {
            $this->finishClaim(
                $claimed,
                $token,
                'failed',
                $origin,
                class_basename($exception),
                'Odeslání upomínky selhalo. Podrobnost je v aplikačním logu.',
            );
            throw $exception;
        }
    }

    /** @return array{InvoiceDocument,string} */
    private function deliveryAssets(InvoiceReminder $reminder, InvoiceReminderOrigin $origin): array
    {
        $invoice = Invoice::query()->whereKey($reminder->invoice_id)->first();
        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice' => 'Faktura již neexistuje.']);
        }
        $invoice->load([
            'issuedRevision.customerSnapshot',
            'issuedRevision.supplierSnapshot',
            'payments',
            'reminderOverride',
        ]);
        $summary = $this->assertEligible($invoice, $origin);
        $setting = $this->settings->current();
        $rendered = $this->renderer->reminder(
            $invoice,
            $setting->{"reminder_subject_{$reminder->level}"},
            $setting->{"reminder_body_{$reminder->level}"},
            $summary->remainingTotal,
            $this->businessDate->daysBetween($invoice->due_on, $reminder->scheduled_on),
        );
        $webInvoiceUrl = $rendered['web_invoice_url'];
        $document = $this->pdfGenerator->generate($invoice->uuid, (string) Str::uuid());
        if ((int) $document->invoice_revision_id !== (int) $invoice->issued_revision_id
            || $document->storage_disk !== InvoicePdfGenerator::DISK
            || $document->mime_type !== 'application/pdf'
            || ! Storage::disk(InvoicePdfGenerator::DISK)->exists($document->storage_path)) {
            throw ValidationException::withMessages(['pdf' => 'Aktuální PDF faktury není bezpečně dostupné.']);
        }
        $connection = $this->connectionResolver->resolve()->connectionName();
        DB::connection($connection)->transaction(function () use ($reminder, $rendered): void {
            $locked = InvoiceReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'sent') {
                return;
            }
            $locked->forceFill([
                'recipient_email' => $rendered['recipient'],
                'subject' => $rendered['subject'],
                'body_text' => $rendered['body'],
            ])->save();
        }, 3);

        return [$document, $webInvoiceUrl];
    }

    /** @return null|array{InvoiceReminder,string} */
    private function claim(InvoiceReminder $reminder, InvoiceReminderOrigin $origin): ?array
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($reminder, $origin): ?array {
            $invoice = Invoice::query()->whereKey($reminder->invoice_id)->lockForUpdate()->first();
            if ($invoice === null) {
                return null;
            }
            $invoice->load(['issuedRevision', 'payments', 'reminderOverride']);
            try {
                $this->assertEligible($invoice, $origin);
            } catch (ValidationException) {
                return null;
            }

            $locked = InvoiceReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'sent') {
                return null;
            }
            if ($locked->status === 'sending'
                && $locked->claimed_at !== null
                && $locked->claimed_at->isAfter(now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))) {
                return null;
            }

            $token = (string) Str::uuid();
            $locked->forceFill([
                'status' => 'sending',
                'claim_token' => $token,
                'claimed_at' => now(),
                'send_attempts' => $locked->send_attempts + 1,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();

            return [$locked, $token];
        }, 3);
    }

    private function assertEligible(Invoice $invoice, InvoiceReminderOrigin $origin): InvoicePaymentSummary
    {
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['invoice' => 'Upomínku lze odeslat pouze k vystavené faktuře.']);
        }
        if ($invoice->archived_at !== null) {
            throw ValidationException::withMessages(['invoice' => 'K archivované faktuře nelze odeslat upomínku.']);
        }
        if ($origin === InvoiceReminderOrigin::Automatic && $invoice->reminderOverride?->disabled) {
            throw ValidationException::withMessages(['invoice' => 'Automatické upomínky jsou pro tuto fakturu vypnuté.']);
        }

        $summary = $this->payments->summary($invoice);
        if (! $summary->isOverdue
            || ! in_array($summary->status, [InvoicePaymentStatus::Unpaid, InvoicePaymentStatus::PartiallyPaid], true)) {
            throw ValidationException::withMessages(['invoice' => 'Upomínku lze odeslat pouze k faktuře po splatnosti s nedoplatkem.']);
        }

        return $summary;
    }

    private function finishClaim(
        InvoiceReminder $reminder,
        string $token,
        string $status,
        InvoiceReminderOrigin $origin,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): InvoiceReminder {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($reminder, $token, $status, $origin, $failureCode, $failureMessage): InvoiceReminder {
            $locked = InvoiceReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'sending' || ! hash_equals((string) $locked->claim_token, $token)) {
                return $locked;
            }
            $locked->forceFill([
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
                'claim_token' => null,
                'claimed_at' => null,
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ])->save();

            if ($status === 'sent') {
                $invoiceUuid = Invoice::query()->whereKey($locked->invoice_id)->value('uuid');
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoiceReminderSent,
                    BusinessAuditableType::Invoice,
                    $invoiceUuid,
                    null,
                    [
                        'invoice_uuid' => $invoiceUuid,
                        'reminder_uuid' => $locked->uuid,
                        'level' => $locked->level,
                        'recipient' => $locked->recipient_email,
                        'origin' => $origin->value,
                        'sent_at' => $locked->sent_at?->toIso8601String(),
                        'status' => $locked->status,
                    ],
                    ['status'],
                );
            }

            return $locked;
        }, 3);
    }
}
