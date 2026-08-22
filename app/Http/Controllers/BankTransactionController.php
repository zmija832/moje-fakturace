<?php

namespace App\Http\Controllers;

use App\Enums\BusinessAuditableType;
use App\Enums\BusinessAuditEvent;
use App\Enums\InvoiceStatus;
use App\Http\Requests\ManageBankAccountRequest;
use App\Http\Requests\MatchBankTransactionRequest;
use App\Models\Business\BankAccount;
use App\Models\Business\BankTransaction;
use App\Models\Business\Invoice;
use App\Services\Business\BankTransactionMatcher;
use App\Services\Business\BusinessAuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BankTransactionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', BankAccount::class);
        $status = in_array($request->query('status'), ['unmatched', 'matched', 'ignored'], true) ? $request->query('status') : 'unmatched';
        $transactions = BankTransaction::query()->with(['bankAccount', 'invoice', 'payment'])->where('status', $status)->latest('booked_on')->latest('id')->paginate(50)->withQueryString();
        $invoices = Invoice::query()->with('issuedRevision.bankAccountSnapshot')->where('status', InvoiceStatus::Issued->value)->whereNull('archived_at')->latest('issued_on')->limit(300)->get();

        return view('business.bank-transactions.index', compact('transactions', 'invoices', 'status'));
    }

    public function match(MatchBankTransactionRequest $request, string $uuid, BankTransactionMatcher $matcher): RedirectResponse
    {
        $matcher->matchManually($uuid, $request->validated('invoice_uuid'));

        return back()->with('status', 'Bankovní platba byla přiřazena k faktuře.');
    }

    public function ignore(ManageBankAccountRequest $request, string $uuid, BusinessAuditWriter $auditWriter): RedirectResponse
    {
        $connection = BankTransaction::query()->getModel()->getConnectionName();
        DB::connection($connection)->transaction(function () use ($uuid, $auditWriter): void {
            $transaction = BankTransaction::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($transaction->status === 'unmatched', 422);
            $transaction->status = 'ignored';
            $transaction->save();
            $auditWriter->write(BusinessAuditEvent::BankTransactionIgnored, BusinessAuditableType::BankTransaction, $transaction->uuid, ['status' => 'unmatched'], ['status' => 'ignored'], ['status']);
        }, 3);

        return back()->with('status', 'Bankovní platba byla označena jako ignorovaná.');
    }
}
