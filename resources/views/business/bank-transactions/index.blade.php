<x-layouts.app title="Bankovní platby">
    @php($canManage = auth()->user()->can('updateAny', \App\Models\Business\BankAccount::class))
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Platby</p>
        <h1 class="mt-1 text-2xl font-bold">Bankovní platby</h1>
        <p class="mt-2 text-sm text-slate-600">Příchozí pohyby importované z Fio účtů aktivního subjektu.</p>
    </div>
    <nav class="mb-5 flex gap-2" aria-label="Filtr bankovních plateb">
        @foreach(['unmatched'=>'Nespárované','matched'=>'Spárované','ignored'=>'Ignorované'] as $value=>$label)
            <a class="{{ $status === $value ? 'button-primary' : 'button-secondary' }}" href="{{ route('bank-transactions.index', ['status'=>$value]) }}">{{ $label }}</a>
        @endforeach
    </nav>
    <section class="card overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead><tr class="border-b"><th class="p-3">Datum</th><th class="p-3">Účet</th><th class="p-3 text-right">Částka</th><th class="p-3">VS</th><th class="p-3">Protistrana / zpráva</th><th class="p-3">Stav</th><th class="p-3">Akce</th></tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr class="border-b align-top">
                    <td class="p-3 whitespace-nowrap">{{ $transaction->booked_on->format('d. m. Y') }}</td>
                    <td class="p-3">{{ $transaction->bankAccount->name }}</td>
                    <td class="p-3 whitespace-nowrap text-right font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::formatMoney($transaction->amount, $transaction->currency) }}</td>
                    <td class="p-3">{{ $transaction->variable_symbol ?: '—' }}</td>
                    <td class="p-3"><div>{{ $transaction->counterparty_name ?: '—' }}</div>@if($transaction->message)<div class="mt-1 max-w-md text-xs text-slate-500">{{ $transaction->message }}</div>@endif</td>
                    <td class="p-3">{{ ['unmatched'=>'Nespárovaná','matched'=>'Spárovaná','ignored'=>'Ignorovaná'][$transaction->status] }}</td>
                    <td class="p-3">
                        @if($transaction->status === 'matched' && $transaction->invoice)<a class="text-blue-700 underline" href="{{ route('invoices.show', $transaction->invoice->uuid) }}">{{ $transaction->invoice->document_number }}</a>
                        @elseif($transaction->status === 'unmatched' && $canManage)
                            <form method="POST" action="{{ route('bank-transactions.match', $transaction->uuid) }}" class="flex min-w-72 gap-2">@csrf
                                <select name="invoice_uuid" required><option value="">Vyberte fakturu</option>
                                    @foreach($invoices as $invoice)
                                        @if($invoice->currency === $transaction->currency && $invoice->issuedRevision?->bankAccountSnapshot?->source_bank_account_uuid === $transaction->bankAccount->uuid)
                                            <option value="{{ $invoice->uuid }}">{{ $invoice->document_number }} · {{ $invoice->variable_symbol }}</option>
                                        @endif
                                    @endforeach
                                </select><button class="button-secondary" type="submit">Přiřadit</button>
                            </form>
                            <form method="POST" action="{{ route('bank-transactions.ignore', $transaction->uuid) }}" class="mt-2">@csrf @method('PATCH')<button class="text-sm text-slate-600 underline" type="submit">Ignorovat</button></form>
                        @endif
                    </td>
                </tr>
            @empty<tr><td colspan="7" class="p-8 text-center text-slate-500">V tomto filtru nejsou žádné bankovní platby.</td></tr>@endforelse
            </tbody>
        </table>
    </section>
    <div class="mt-5">{{ $transactions->links() }}</div>
</x-layouts.app>
