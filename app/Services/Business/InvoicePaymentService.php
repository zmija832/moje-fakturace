<?php

namespace App\Services\Business;

use App\Domain\Audit\BusinessAuditSanitizer;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\Exceptions\InvoicePaymentIdempotencyConflict;
use App\Domain\Invoices\Exceptions\InvoicePaymentNotAllowed;
use App\Domain\Invoices\Exceptions\InvoicePaymentReversalInvalid;
use App\Domain\Invoices\InvoiceDecimal;
use App\Domain\Invoices\InvoicePaymentEventSnapshot;
use App\Domain\Invoices\InvoicePaymentSummary;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoicePaymentSource;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoicePaymentType;
use App\Enums\InvoiceStatus;
use App\Events\InvoicePaymentChanged;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoicePaymentService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly BusinessAuditSanitizer $auditSanitizer,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    /** @param array<string, mixed> $input */
    public function record(string $invoiceUuid, string $correlationUuid, array $input): InvoicePayment
    {
        $values = $this->validatePaymentInput($invoiceUuid, $correlationUuid, $input);
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            [$payment, $event] = DB::connection($connection)->transaction(
                fn (): array => $this->recordLocked($invoiceUuid, $correlationUuid, $values),
                3,
            );
        } catch (InvoicePaymentNotAllowed|InvoicePaymentIdempotencyConflict|ValidationException $exception) {
            $this->auditConflict($connection, $invoiceUuid, $correlationUuid, $exception);
            throw $exception;
        }

        if ($event !== null) {
            $this->dispatchEventSafely($event);
        }

        return $payment;
    }

    /** @param array<string, mixed> $input */
    public function reverse(string $invoiceUuid, string $paymentUuid, string $correlationUuid, array $input): InvoicePayment
    {
        $values = $this->validateReversalInput($invoiceUuid, $paymentUuid, $correlationUuid, $input);
        $connection = $this->connectionResolver->resolve()->connectionName();

        try {
            [$reversal, $event] = DB::connection($connection)->transaction(
                fn (): array => $this->reverseLocked($invoiceUuid, $paymentUuid, $correlationUuid, $values),
                3,
            );
        } catch (InvoicePaymentNotAllowed|InvoicePaymentIdempotencyConflict|InvoicePaymentReversalInvalid|ValidationException $exception) {
            $this->auditConflict($connection, $invoiceUuid, $correlationUuid, $exception);
            throw $exception;
        }

        if ($event !== null) {
            $this->dispatchEventSafely($event);
        }

        return $reversal;
    }

    /** @param array<string, mixed> $values @return array{InvoicePayment, ?InvoicePaymentEventSnapshot} */
    private function recordLocked(string $invoiceUuid, string $correlationUuid, array $values): array
    {
        $invoice = $this->issuedInvoice($invoiceUuid);
        $existing = InvoicePayment::query()->where('correlation_uuid', $correlationUuid)->lockForUpdate()->first();
        if ($existing !== null) {
            if ((int) $existing->invoice_id !== (int) $invoice->id || $existing->payment_type !== InvoicePaymentType::Payment) {
                throw InvoicePaymentIdempotencyConflict::create();
            }

            return [$existing, null];
        }
        if ($values['currency'] !== $invoice->currency) {
            throw ValidationException::withMessages(['currency' => 'Měna platby musí odpovídat měně faktury.']);
        }

        $before = $this->summary($invoice);
        if (InvoiceDecimal::compare($before->remainingTotal, '0') <= 0
            || InvoiceDecimal::compare($values['amount'], $before->remainingTotal) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Částka úhrady nesmí překročit zbývající částku faktury.',
            ]);
        }
        $payment = new InvoicePayment;
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'payment_type' => InvoicePaymentType::Payment->value,
            'amount' => $values['amount'],
            'currency' => $values['currency'],
            'paid_on' => $values['paid_on'],
            'received_at' => $values['received_at'],
            'payment_method' => $values['payment_method'],
            'reference' => $values['reference'],
            'variable_symbol' => $values['variable_symbol'],
            'note' => $values['note'],
            'source' => InvoicePaymentSource::Manual->value,
            'external_id' => null,
            'correlation_uuid' => $correlationUuid,
            'reverses_payment_id' => null,
            'created_by_actor' => $this->actor(),
        ])->save();

        return $this->finish($invoice, $payment, $before, BusinessAuditEvent::InvoicePaymentRecorded);
    }

    /** @param array<string, mixed> $values @return array{InvoicePayment, ?InvoicePaymentEventSnapshot} */
    private function reverseLocked(string $invoiceUuid, string $paymentUuid, string $correlationUuid, array $values): array
    {
        $invoice = $this->issuedInvoice($invoiceUuid);
        $existing = InvoicePayment::query()->where('correlation_uuid', $correlationUuid)->lockForUpdate()->first();
        if ($existing !== null) {
            $existing->loadMissing('originalPayment');
            if ((int) $existing->invoice_id !== (int) $invoice->id
                || $existing->payment_type !== InvoicePaymentType::Reversal
                || $existing->originalPayment?->uuid !== $paymentUuid) {
                throw InvoicePaymentIdempotencyConflict::create();
            }

            return [$existing, null];
        }

        $original = InvoicePayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('uuid', $paymentUuid)
            ->lockForUpdate()
            ->firstOrFail();
        if ($original->payment_type !== InvoicePaymentType::Payment) {
            throw InvoicePaymentReversalInvalid::create();
        }
        $alreadyReversed = (string) InvoicePayment::query()
            ->where('reverses_payment_id', $original->id)
            ->sum('amount');
        if (InvoiceDecimal::compare(InvoiceDecimal::add($alreadyReversed, $values['amount']), $original->amount) > 0) {
            throw InvoicePaymentReversalInvalid::create();
        }

        $before = $this->summary($invoice);
        if (InvoiceDecimal::compare($values['amount'], $before->paidTotal) > 0) {
            throw InvoicePaymentReversalInvalid::create();
        }
        $reversal = new InvoicePayment;
        $reversal->forceFill([
            'invoice_id' => $invoice->id,
            'payment_type' => InvoicePaymentType::Reversal->value,
            'amount' => $values['amount'],
            'currency' => $invoice->currency,
            'paid_on' => $values['paid_on'],
            'received_at' => null,
            'payment_method' => $original->payment_method->value,
            'reference' => null,
            'variable_symbol' => $original->variable_symbol,
            'note' => $values['reason'],
            'source' => InvoicePaymentSource::Manual->value,
            'external_id' => null,
            'correlation_uuid' => $correlationUuid,
            'reverses_payment_id' => $original->id,
            'created_by_actor' => $this->actor(),
        ])->save();
        $reversal->setRelation('originalPayment', $original);

        return $this->finish($invoice, $reversal, $before, BusinessAuditEvent::InvoicePaymentReversed);
    }

    /** @return array{InvoicePayment, InvoicePaymentEventSnapshot} */
    private function finish(Invoice $invoice, InvoicePayment $payment, InvoicePaymentSummary $before, BusinessAuditEvent $event): array
    {
        $after = $this->summary($invoice);
        $payment->setRelation('invoice', $invoice);
        $snapshot = $this->auditSanitizer->snapshot(BusinessAuditableType::InvoicePayment, $payment);
        $snapshot += [
            'status_before' => $before->status->value,
            'status_after' => $after->status->value,
            'paid_total' => $after->paidTotal,
            'remaining_total' => $after->remainingTotal,
        ];
        $this->auditWriter->write(
            $event,
            BusinessAuditableType::InvoicePayment,
            $payment->uuid,
            null,
            $snapshot,
            ['ledger_entry'],
            BusinessAuditableType::Invoice,
            $invoice->uuid,
        );
        if ($before->status !== $after->status) {
            $this->auditWriter->write(
                BusinessAuditEvent::InvoicePaymentStatusChanged,
                BusinessAuditableType::Invoice,
                $invoice->uuid,
                ['payment_status' => $before->status->value, 'paid_total' => $before->paidTotal, 'remaining_total' => $before->remainingTotal],
                ['payment_status' => $after->status->value, 'paid_total' => $after->paidTotal, 'remaining_total' => $after->remainingTotal],
                ['payment_status', 'paid_total', 'remaining_total'],
                BusinessAuditableType::InvoicePayment,
                $payment->uuid,
            );
        }

        return [$payment, $this->domainEvent($invoice, $payment, $before, $after)];
    }

    private function issuedInvoice(string $invoiceUuid): Invoice
    {
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw InvoicePaymentNotAllowed::create();
        }

        return $invoice->load('issuedRevision:id,invoice_id,grand_total');
    }

    private function summary(Invoice $invoice): InvoicePaymentSummary
    {
        $entries = InvoicePayment::query()->where('invoice_id', $invoice->id)->oldest('id')->get(['payment_type', 'amount']);

        return InvoicePaymentSummary::fromLedger(
            $invoice->issuedRevision->grand_total,
            $entries->map(fn (InvoicePayment $entry): array => ['payment_type' => $entry->payment_type, 'amount' => $entry->amount]),
            $invoice->due_on,
            today(),
        );
    }

    private function domainEvent(Invoice $invoice, InvoicePayment $payment, InvoicePaymentSummary $before, InvoicePaymentSummary $after): InvoicePaymentEventSnapshot
    {
        $intents = [];
        if ($payment->payment_type === InvoicePaymentType::Payment) {
            $intents[] = 'admin.invoice.payment_recorded';
            $intents[] = match ($after->status) {
                InvoicePaymentStatus::PartiallyPaid => 'client.invoice.payment_partial_confirmation',
                InvoicePaymentStatus::Overpaid => 'client.invoice.payment_overpayment_notice',
                default => 'client.invoice.payment_confirmation',
            };
        }
        $intents[] = match ($after->status) {
            InvoicePaymentStatus::Unpaid => 'admin.invoice.unpaid',
            InvoicePaymentStatus::PartiallyPaid => 'admin.invoice.partially_paid',
            InvoicePaymentStatus::Paid => 'admin.invoice.paid',
            InvoicePaymentStatus::Overpaid => 'admin.invoice.overpaid',
        };
        if ($after->isOverdue) {
            $intents[] = 'admin.invoice.overdue';
        }

        return new InvoicePaymentEventSnapshot(
            $invoice->uuid,
            (string) $invoice->document_number,
            $payment->uuid,
            $payment->payment_type->value,
            $payment->amount,
            $payment->currency,
            $payment->paid_on->format('Y-m-d'),
            $before->status->value,
            $after->status->value,
            $after->paidTotal,
            $after->remainingTotal,
            array_values(array_unique($intents)),
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validatePaymentInput(string $invoiceUuid, string $correlationUuid, array $input): array
    {
        $this->validateUuids([$invoiceUuid, $correlationUuid]);

        try {
            $paymentMethod = DefaultPaymentMethod::from((string) ($input['payment_method'] ?? ''));
        } catch (Throwable) {
            throw ValidationException::withMessages(['payment_method' => 'Způsob platby není podporovaný.']);
        }

        return [
            'amount' => $this->positiveAmount($input['amount'] ?? null),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? ''))),
            'paid_on' => $this->date($input['paid_on'] ?? null, 'paid_on'),
            'received_at' => isset($input['received_at']) ? CarbonImmutable::parse((string) $input['received_at']) : null,
            'payment_method' => $paymentMethod->value,
            'reference' => $this->nullableString($input['reference'] ?? null, 255),
            'variable_symbol' => $this->nullableString($input['variable_symbol'] ?? null, 20),
            'note' => $this->nullableString($input['note'] ?? null, 2000),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateReversalInput(string $invoiceUuid, string $paymentUuid, string $correlationUuid, array $input): array
    {
        $this->validateUuids([$invoiceUuid, $paymentUuid, $correlationUuid]);

        return [
            'amount' => $this->positiveAmount($input['amount'] ?? null),
            'paid_on' => $this->date($input['reversed_on'] ?? null, 'reversed_on'),
            'reason' => $this->requiredString($input['reason'] ?? null, 2000, 'reason'),
        ];
    }

    /** @param list<string> $uuids */
    private function validateUuids(array $uuids): void
    {
        foreach ($uuids as $uuid) {
            if (! Str::isUuid($uuid)) {
                throw ValidationException::withMessages(['correlation_uuid' => 'Identifikátor platební operace má neplatný formát.']);
            }
        }
    }

    private function positiveAmount(mixed $value): string
    {
        try {
            $amount = InvoiceDecimal::money($value);
        } catch (Throwable) {
            throw ValidationException::withMessages(['amount' => 'Částka musí být přesné desetinné číslo s nejvýše čtyřmi desetinnými místy.']);
        }
        if (InvoiceDecimal::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => 'Částka musí být větší než nula.']);
        }

        return $amount;
    }

    private function date(mixed $value, string $field): string
    {
        $value = (string) $value;
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Datum má neplatný formát.']);
        }

        return $value;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['payment' => 'Text platebního záznamu je příliš dlouhý.']);
        }

        return $value === '' ? null : $value;
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        $value = $this->nullableString($value, $max);
        if ($value === null) {
            throw ValidationException::withMessages([$field => 'Důvod storna je povinný.']);
        }

        return $value;
    }

    private function actor(): ?string
    {
        $user = auth()->user();

        return $user ? 'central-user:'.$user->getAuthIdentifier() : null;
    }

    private function auditConflict(string $connection, string $invoiceUuid, string $correlationUuid, Throwable $exception): void
    {
        try {
            DB::connection($connection)->transaction(function () use ($invoiceUuid, $correlationUuid, $exception): void {
                $invoice = Invoice::query()->where('uuid', $invoiceUuid)->lockForUpdate()->first();
                if ($invoice === null) {
                    return;
                }
                $reason = match (true) {
                    $exception instanceof InvoicePaymentIdempotencyConflict => 'idempotency_conflict',
                    $exception instanceof InvoicePaymentReversalInvalid => 'invalid_reversal',
                    $exception instanceof InvoicePaymentNotAllowed => 'invoice_not_issued',
                    default => 'validation_conflict',
                };
                $this->auditWriter->write(
                    BusinessAuditEvent::InvoicePaymentConflict,
                    BusinessAuditableType::Invoice,
                    $invoice->uuid,
                    null,
                    null,
                    [],
                    metadata: ['reason' => $reason, 'correlation_uuid' => $correlationUuid],
                );
            }, 3);
        } catch (Throwable) {
            // Konflikt nesmí změnit výsledek původní atomické platební operace.
        }
    }

    private function dispatchEventSafely(InvoicePaymentEventSnapshot $snapshot): void
    {
        try {
            Event::dispatch(new InvoicePaymentChanged($snapshot));
        } catch (Throwable $exception) {
            // Notifikační integrace běží až po potvrzení ledger transakce. Její selhání
            // nesmí změnit úspěšný platební zápis na HTTP 500 a vyvolat opakování platby.
            Log::error('Následné zpracování zaevidované platby selhalo.', [
                'invoice_uuid' => $snapshot->invoiceUuid,
                'payment_uuid' => $snapshot->paymentUuid,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
