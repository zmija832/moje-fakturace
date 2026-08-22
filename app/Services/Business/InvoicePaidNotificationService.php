<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;
use App\Mail\AutomationMail;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePaidNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class InvoicePaidNotificationService
{
    private const CLAIM_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly InvoiceAutomationSettingsService $settings,
        private readonly AutomationTemplateRenderer $renderer,
        private readonly InvoiceEmailSettingsService $emailSettings,
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoicePaymentReader $payments,
        private readonly ActiveBusinessContext $businessContext,
    ) {}

    public function handle(InvoicePaymentEventSnapshot $event): void
    {
        if ($event->paymentType !== 'payment'
            || ! in_array($event->statusAfter, ['partially_paid', 'paid', 'overpaid'], true)) {
            return;
        }

        $invoice = Invoice::query()->where('uuid', $event->invoiceUuid)->firstOrFail();
        $setting = $this->settings->current();
        if ($setting->notify_admin_when_paid) {
            $rendered = $this->renderer->paymentNotification($invoice, $event, 'admin');
            $this->create($invoice, $event, 'admin', null, 'internal', $rendered['subject'], $rendered['body']);
            [$notification, $created] = $this->create($invoice, $event, 'admin_email', $this->adminRecipient(), 'prepared', $rendered['subject'], $rendered['body']);
            if ($created) {
                $this->send($notification);
            }
        }
        if ($setting->notify_customer_when_paid) {
            $rendered = $event->statusAfter === 'paid'
                ? $this->renderer->paid($invoice, $setting->paid_subject, $setting->paid_body, $event->paidOn)
                : $this->renderer->paymentNotification($invoice, $event, 'customer');
            [$notification, $created] = $this->create(
                $invoice,
                $event,
                'customer',
                $invoice->issuedRevision()->with('customerSnapshot')->firstOrFail()->customerSnapshot->email,
                'prepared',
                $rendered['subject'],
                $rendered['body'],
            );
            if ($created) {
                $this->send($notification);
            }
        }
    }

    /** @return array{processed:int,failed:int} */
    public function retryDue(int $limit = 50): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        $notifications = InvoicePaidNotification::query()
            ->whereIn('recipient_type', ['admin_email', 'customer'])
            ->where(function ($query): void {
                $query->whereIn('status', ['prepared', 'failed'])
                    ->orWhere(function ($query): void {
                        $query->where('status', 'sending')
                            ->where(function ($query): void {
                                $query->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<=', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
                            });
                    });
            })
            ->oldest('id')
            ->limit($limit)
            ->get();

        foreach ($notifications as $notification) {
            try {
                $attempts = $notification->send_attempts;
                $after = $this->send($notification);
                if ($after->send_attempts > $attempts) {
                    $result['processed']++;
                    if ($after->status === 'failed') {
                        $result['failed']++;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function send(InvoicePaidNotification $notification): InvoicePaidNotification
    {
        $claim = $this->claim($notification);
        if ($claim === null) {
            return $notification->refresh();
        }
        [$claimed, $token] = $claim;

        if (! filter_var($claimed->recipient_email, FILTER_VALIDATE_EMAIL)) {
            return $this->finishClaim(
                $claimed,
                $token,
                'failed',
                'recipient_missing',
                $claimed->recipient_type === 'customer'
                    ? 'Klient nemá platnou e-mailovou adresu.'
                    : 'Fakturační subjekt nemá dostupného administrátora s platnou e-mailovou adresou.',
            );
        }

        try {
            $email = $this->emailSettings->current();
            Mail::to($claimed->recipient_email)->send(new AutomationMail(
                $claimed->subject,
                $claimed->body_text,
                $email->sender_name,
                $email->reply_to,
            ));

            return $this->finishClaim($claimed, $token, 'sent');
        } catch (Throwable $exception) {
            report($exception);

            return $this->finishClaim(
                $claimed,
                $token,
                'failed',
                class_basename($exception),
                'Odeslání platební notifikace selhalo. Podrobnost je v aplikačním logu.',
            );
        }
    }

    /**
     * @return array{InvoicePaidNotification,bool}
     */
    private function create(
        Invoice $invoice,
        InvoicePaymentEventSnapshot $event,
        string $type,
        ?string $recipient,
        string $status,
        string $subjectTemplate,
        string $bodyTemplate,
    ): array {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($invoice, $event, $type, $recipient, $status, $subjectTemplate, $bodyTemplate): array {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing = InvoicePaidNotification::query()
                ->where('triggering_payment_uuid', $event->paymentUuid)
                ->where('recipient_type', $type)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $notification = new InvoicePaidNotification;
            $notification->forceFill([
                'invoice_id' => $lockedInvoice->id,
                'triggering_payment_uuid' => $event->paymentUuid,
                'recipient_type' => $type,
                'recipient_email' => $recipient,
                'subject' => $subjectTemplate,
                'body_text' => $bodyTemplate,
                'status' => $status,
                'correlation_uuid' => (string) Str::uuid(),
            ]);
            $notification->save();

            return [$notification, true];
        }, 3);
    }

    /** @return null|array{InvoicePaidNotification,string} */
    private function claim(InvoicePaidNotification $notification): ?array
    {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($notification): ?array {
            $invoice = Invoice::query()->whereKey($notification->invoice_id)->lockForUpdate()->first();
            if ($invoice === null || $invoice->status !== InvoiceStatus::Issued || $invoice->archived_at !== null) {
                return null;
            }
            $invoice->load(['issuedRevision', 'payments']);
            $summary = $this->payments->summary($invoice);
            if (! in_array($summary->status, [InvoicePaymentStatus::PartiallyPaid, InvoicePaymentStatus::Paid, InvoicePaymentStatus::Overpaid], true)) {
                return null;
            }

            $locked = InvoicePaidNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->recipient_type, ['admin_email', 'customer'], true) || $locked->status === 'sent') {
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

    private function finishClaim(
        InvoicePaidNotification $notification,
        string $token,
        string $status,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): InvoicePaidNotification {
        $connection = $this->connectionResolver->resolve()->connectionName();

        return DB::connection($connection)->transaction(function () use ($notification, $token, $status, $failureCode, $failureMessage): InvoicePaidNotification {
            $locked = InvoicePaidNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
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

            return $locked;
        }, 3);
    }

    private function adminRecipient(): ?string
    {
        $business = $this->businessContext->requireBusiness();
        if (! $business->exists) {
            return null;
        }

        $email = $business->users()
            ->wherePivot('role', 'admin')
            ->orderBy('users.id')
            ->value('users.email');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? (string) $email : null;
    }
}
