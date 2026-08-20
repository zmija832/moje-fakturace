@props(['invoice'])

@php
    $statusLabels = [
        'prepared' => 'Připravená',
        'sending' => 'Odesílá se',
        'sent' => 'Odeslaná',
        'failed' => 'Selhala',
    ];
    $statusClasses = [
        'prepared' => 'bg-blue-100 text-blue-800',
        'sending' => 'bg-amber-100 text-amber-900',
        'sent' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-red-100 text-red-800',
    ];
@endphp

<section id="invoice-reminder-history" class="card mt-6 scroll-mt-6">
    <h2 class="text-lg font-bold">Historie upomínek</h2>
    <p class="mt-1 text-sm text-slate-600">Připravené a skutečně odeslané upomínky k této faktuře.</p>

    @if($invoice->reminders->isEmpty())
        <p class="mt-4 text-sm text-slate-600">K faktuře zatím nebyla připravena žádná upomínka.</p>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead><tr><th class="p-2">Stupeň</th><th class="p-2">Plán</th><th class="p-2">Stav</th><th class="p-2">Příjemce</th><th class="p-2">Odesláno</th><th class="p-2 text-right">Pokusy</th></tr></thead>
                <tbody>
                    @foreach($invoice->reminders as $reminder)
                        <tr class="border-t border-slate-200">
                            <td class="p-2 font-semibold">{{ $reminder->level }}. upomínka</td>
                            <td class="p-2">{{ $reminder->scheduled_on->format('j. n. Y') }}</td>
                            <td class="p-2"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$reminder->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$reminder->status] ?? $reminder->status }}</span></td>
                            <td class="p-2">{{ $reminder->recipient_email ?: '—' }}</td>
                            <td class="p-2">{{ $reminder->sent_at?->format('j. n. Y H:i:s') ?? '—' }}</td>
                            <td class="p-2 text-right tabular-nums">{{ $reminder->send_attempts }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
