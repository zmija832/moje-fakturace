@props(['invoice', 'summary', 'paymentMethods', 'correlationUuid'])
@php $canManage = auth()->user()->can('recordPayment', $invoice); @endphp
<section class="card mt-6" aria-labelledby="invoice-payments-heading">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div><h2 id="invoice-payments-heading" class="text-lg font-bold">Platby</h2><p class="mt-1 text-sm text-slate-600">Neměnná historie přijatých plateb a jejich storen.</p></div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-800">{{ $summary->status->label() }}</span>
    </div>
    <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div><dt class="text-sm text-slate-500">Celková částka</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($summary->grandTotal) }} {{ $invoice->currency }}</dd></div>
        <div><dt class="text-sm text-slate-500">Uhrazeno</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($summary->paidTotal) }} {{ $invoice->currency }}</dd></div>
        <div><dt class="text-sm text-slate-500">Zbývá</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($summary->remainingTotal) }} {{ $invoice->currency }}</dd></div>
        <div><dt class="text-sm text-slate-500">Přeplatek</dt><dd class="font-semibold">{{ \App\Domain\Invoices\InvoiceDecimal::format($summary->overpaymentTotal) }} {{ $invoice->currency }}</dd></div>
    </dl>
    @if($summary->isOverdue)<p class="mt-4 text-sm font-semibold text-red-700">Faktura má neuhrazený zůstatek po splatnosti.</p>@endif

    @if($invoice->payments->isEmpty())
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Zatím nebyla zaevidována žádná platba.</p>
    @else
        <div class="mt-6 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b border-slate-200"><th class="py-2 pr-4">Datum</th><th class="py-2 pr-4">Typ</th><th class="py-2 pr-4 text-right">Částka</th><th class="py-2 pr-4">Způsob a reference</th><th class="py-2 pr-4">Aktér</th><th class="py-2"></th></tr></thead><tbody class="divide-y divide-slate-100">
        @foreach($invoice->payments as $payment)
            @php
                $reversed = '0.0000';
                foreach($payment->reversals as $child) { $reversed = \App\Domain\Invoices\InvoiceDecimal::add($reversed, $child->amount); }
                $reversible = $payment->payment_type === \App\Enums\InvoicePaymentType::Payment
                    && \App\Domain\Invoices\InvoiceDecimal::compare($reversed, $payment->amount) < 0;
                $available = $reversible ? \App\Domain\Invoices\InvoiceDecimal::subtract($payment->amount, $reversed) : '0.0000';
            @endphp
            <tr><td class="py-3 pr-4">{{ $payment->paid_on->format('j. n. Y') }}</td><td class="py-3 pr-4"><span class="font-medium">{{ $payment->payment_type->label() }}</span>@if($payment->originalPayment)<span class="block text-xs text-slate-500">k platbě {{ $payment->originalPayment->uuid }}</span>@endif</td><td class="py-3 pr-4 text-right font-semibold">{{ $payment->payment_type === \App\Enums\InvoicePaymentType::Reversal ? '−' : '' }}{{ \App\Domain\Invoices\InvoiceDecimal::format($payment->amount) }} {{ $payment->currency }}</td><td class="py-3 pr-4">{{ $payment->payment_method->label() }}
                @if($payment->reference)<span class="block text-xs text-slate-500">{{ $payment->reference }}</span>@endif
                @if($payment->note)<span class="block text-xs text-slate-500">{{ $payment->note }}</span>@endif
            </td><td class="py-3 pr-4 text-xs text-slate-500">{{ $payment->created_by_actor ?? 'Systém' }}</td><td class="py-3">
            @if($canManage && $reversible)
                <details><summary class="cursor-pointer text-sm font-semibold text-blue-700">Stornovat</summary><form method="POST" action="{{ route('invoices.payments.reverse', [$invoice->uuid, $payment->uuid]) }}" class="mt-3 min-w-64 space-y-3">@csrf<input type="hidden" name="correlation_uuid" value="{{ \Illuminate\Support\Str::uuid() }}"><div><label for="reversal-amount-{{ $payment->uuid }}">Částka storna</label><input id="reversal-amount-{{ $payment->uuid }}" name="amount" value="{{ $available }}" inputmode="decimal" required></div><div><label for="reversed-on-{{ $payment->uuid }}">Datum storna</label><input id="reversed-on-{{ $payment->uuid }}" name="reversed_on" type="date" value="{{ today()->format('Y-m-d') }}" required></div><div><label for="reason-{{ $payment->uuid }}">Důvod</label><textarea id="reason-{{ $payment->uuid }}" name="reason" maxlength="2000" required></textarea></div><button class="button-secondary" type="submit">Vytvořit storno</button></form></details>
            @endif
            </td></tr>
        @endforeach
        </tbody></table></div>
    @endif

    @if($canManage)
        <form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" class="mt-6 border-t border-slate-200 pt-6">@csrf<input type="hidden" name="correlation_uuid" value="{{ $correlationUuid }}"><input type="hidden" name="currency" value="{{ $invoice->currency }}"><h3 class="font-semibold">Přidat platbu</h3><div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"><div><label for="payment-amount">Částka</label><input id="payment-amount" name="amount" value="{{ old('amount', \App\Domain\Invoices\InvoiceDecimal::compare($summary->remainingTotal, '0') > 0 ? $summary->remainingTotal : '') }}" inputmode="decimal" required></div><div><label for="payment-paid-on">Datum platby</label><input id="payment-paid-on" name="paid_on" type="date" value="{{ old('paid_on', today()->format('Y-m-d')) }}" required></div><div><label for="payment-method">Způsob</label><select id="payment-method" name="payment_method" required>@foreach($paymentMethods as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method', $invoice->payment_method->value) === $value)>{{ $label }}</option>@endforeach</select></div><div><label for="payment-variable-symbol">Variabilní symbol</label><input id="payment-variable-symbol" name="variable_symbol" maxlength="20" value="{{ old('variable_symbol', $invoice->variable_symbol) }}"></div><div class="md:col-span-2"><label for="payment-reference">Reference</label><input id="payment-reference" name="reference" maxlength="255" value="{{ old('reference') }}"></div><div class="md:col-span-2"><label for="payment-note">Poznámka</label><textarea id="payment-note" name="note" maxlength="2000">{{ old('note') }}</textarea></div></div><button class="button-primary mt-4" type="submit">Zaevidovat platbu</button></form>
    @endif
</section>
