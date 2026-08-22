<?php

namespace App\Services\Business;

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
        private readonly BusinessAuditWriter $auditWriter,
    ) {}

    public function matchAutomatically(BankTransaction $transaction): bool
    {
        if ($transaction->status !== 'unmatched' || ! preg_match('/^[0-9]{1,10}$/', (string) $transaction->variable_symbol)) {
            return false;
        }

        $candidates = $this->candidateQuery($transaction)->limit(2)->get();
        if ($candidates->count() !== 1) {
            return false;
        }

        $this->apply($transaction, $candidates->firstOrFail(), 'automatic');

        return true;
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

    private function candidateQuery(BankTransaction $transaction)
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->whereNull('archived_at')
            ->where('currency', $transaction->currency)
            ->where('variable_symbol', $transaction->variable_symbol)
            ->whereHas('issuedRevision.bankAccountSnapshot', fn ($query) => $query->where('source_bank_account_uuid', $transaction->bankAccount->uuid));
    }

    private function isEligible(BankTransaction $transaction, Invoice $invoice): bool
    {
        if ($transaction->status !== 'unmatched'
            || $invoice->status !== InvoiceStatus::Issued
            || $invoice->archived_at !== null
            || $invoice->currency !== $transaction->currency) {
            return false;
        }

        return $invoice->issuedRevision()
            ->whereHas('bankAccountSnapshot', fn ($query) => $query->where('source_bank_account_uuid', $transaction->bankAccount->uuid))
            ->exists();
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
}
