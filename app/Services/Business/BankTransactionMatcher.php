<?php

namespace App\Services\Business;

use App\Domain\Banking\BankTransactionMatchResult;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\BankTransactionUnmatchedReason;
use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\DefaultPaymentMethod;
use App\Enums\InvoiceStatus;
use App\Models\Business\BankTransaction;
use App\Models\Business\Invoice;
use App\Models\Business\InvoicePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankTransactionMatcher
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoicePaymentReader $paymentReader,
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function matchAutomatically(BankTransaction $transaction): BankTransactionMatchResult
    {
        if ($transaction->status !== 'unmatched') {
            return BankTransactionMatchResult::skipped();
        }

        if (blank($transaction->variable_symbol)) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::MissingVariableSymbol);
        }

        if (! preg_match('/^[0-9]{1,10}$/', (string) $transaction->variable_symbol)) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::InvalidVariableSymbol);
        }

        $sameVariableSymbol = Invoice::query()->where('variable_symbol', $transaction->variable_symbol);
        if (! (clone $sameVariableSymbol)->exists()) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::NoInvoiceCandidate);
        }

        $eligible = (clone $sameVariableSymbol)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereNull('archived_at');
        if (! (clone $eligible)->exists()) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::InvoiceNotEligible);
        }

        $sameCurrency = (clone $eligible)->where('currency', $transaction->currency);
        if (! (clone $sameCurrency)->exists()) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::CurrencyMismatch);
        }

        $sameAccount = (clone $sameCurrency)
            ->whereHas('issuedRevision.bankAccountSnapshot', fn ($query) => $query->where('source_bank_account_uuid', $transaction->bankAccount->uuid));
        if (! (clone $sameAccount)->exists()) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::BankAccountMismatch);
        }

        $candidates = $sameAccount
            ->with(['issuedRevision:id,invoice_id,grand_total', 'payments.originalPayment'])
            ->get()
            ->filter(fn (Invoice $invoice): bool => $this->hasOutstandingBalance($invoice))
            ->take(2);
        if ($candidates->isEmpty()) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::InvoiceAlreadyPaid);
        }
        if ($candidates->count() > 1) {
            return $this->unmatched($transaction, BankTransactionUnmatchedReason::MultipleInvoiceCandidates);
        }

        $this->apply($transaction, $candidates->firstOrFail(), 'automatic');

        return BankTransactionMatchResult::matched();
    }

    public function matchManually(string $transactionUuid, string $invoiceUuid): BankTransaction
    {
        $transaction = BankTransaction::query()->where('uuid', $transactionUuid)->firstOrFail();
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->firstOrFail();

        if (! $this->isEligible($transaction, $invoice)) {
            throw ValidationException::withMessages(['invoice_uuid' => 'Vybranou fakturu nelze k této bankovní platbě bezpečně přiřadit.']);
        }

        $this->apply($transaction, $invoice, 'manual');

        return $transaction->refresh()->load(['invoice', 'payment']);
    }

    private function isEligible(BankTransaction $transaction, Invoice $invoice): bool
    {
        if ($transaction->status !== 'unmatched'
            || $invoice->status !== InvoiceStatus::Issued
            || $invoice->archived_at !== null
            || $invoice->currency !== $transaction->currency
            || ! $this->hasOutstandingBalance($invoice)) {
            return false;
        }

        return $invoice->issuedRevision()
            ->whereHas('bankAccountSnapshot', fn ($query) => $query->where('source_bank_account_uuid', $transaction->bankAccount->uuid))
            ->exists();
    }

    private function hasOutstandingBalance(Invoice $invoice): bool
    {
        return InvoiceDecimal::compare($this->paymentReader->summary($invoice)->remainingTotal, '0') > 0;
    }

    private function apply(BankTransaction $transaction, Invoice $invoice, string $method): void
    {
        $externalId = 'fio:'.$transaction->bankAccount->uuid.':'.$transaction->external_transaction_id;
        $this->paymentService->recordImported(
            $invoice->uuid,
            $transaction->uuid,
            $externalId,
            [
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'paid_on' => $transaction->booked_on->format('Y-m-d'),
                'payment_method' => DefaultPaymentMethod::BankTransfer->value,
                'reference' => $transaction->external_transaction_id,
                'variable_symbol' => $transaction->variable_symbol,
            ],
            function (InvoicePayment $payment, Invoice $lockedInvoice) use ($transaction, $method): void {
                $locked = BankTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'matched') {
                    if ((int) $locked->invoice_payment_id !== (int) $payment->id) {
                        throw ValidationException::withMessages(['transaction' => 'Bankovní platba již byla přiřazena jinam.']);
                    }

                    return;
                }
                if ($locked->status !== 'unmatched') {
                    throw ValidationException::withMessages(['transaction' => 'Bankovní platbu v tomto stavu nelze přiřadit.']);
                }

                DB::connection($locked->getConnectionName())->table('bank_transactions')->where('id', $locked->id)->update([
                    'status' => 'matched',
                    'unmatched_reason' => null,
                    'matched_invoice_id' => $lockedInvoice->id,
                    'invoice_payment_id' => $payment->id,
                    'matched_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->auditWriter->write(
                    $method === 'automatic' ? BusinessAuditEvent::BankTransactionAutomaticallyMatched : BusinessAuditEvent::BankTransactionManuallyMatched,
                    BusinessAuditableType::BankTransaction,
                    $locked->uuid,
                    ['status' => 'unmatched'],
                    ['status' => 'matched'],
                    ['status'],
                    BusinessAuditableType::Invoice,
                    $lockedInvoice->uuid,
                    [
                        'bank_transaction_uuid' => $locked->uuid,
                        'invoice_uuid' => $lockedInvoice->uuid,
                        'payment_uuid' => $payment->uuid,
                        'bank_account_uuid' => $locked->bankAccount->uuid,
                        'amount' => $locked->amount,
                        'currency' => $locked->currency,
                        'matching_method' => $method,
                    ],
                );
            },
        );
    }

    private function unmatched(BankTransaction $transaction, BankTransactionUnmatchedReason $reason): BankTransactionMatchResult
    {
        $connection = $transaction->getConnectionName();
        DB::connection($connection)->transaction(function () use ($transaction, $reason): void {
            DB::connection($transaction->getConnectionName())
                ->table('bank_transactions')
                ->where('id', $transaction->id)
                ->where('status', 'unmatched')
                ->lockForUpdate()
                ->update([
                    'unmatched_reason' => $reason->value,
                    'updated_at' => now(),
                ]);
        }, 3);

        return BankTransactionMatchResult::unmatched($reason);
    }
}
