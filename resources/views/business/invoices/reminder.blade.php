@php($remindersByLevel = $invoice->reminders->keyBy('level'))

<x-layouts.app title="Odeslat upomínku">
    <h1 class="text-3xl font-bold">Odeslat upomínku</h1>
    <div class="card mt-6">
        <p>Faktura <strong>{{ $invoice->document_number }}</strong></p>
        <p class="text-sm text-slate-600">Příjemce: {{ $invoice->issuedRevision->customerSnapshot->email ?: 'chybí e-mail' }}</p>
        <form method="POST" action="{{ route('invoices.reminders.send', $invoice->uuid) }}" class="mt-5 space-y-4" x-data="{ level: '1' }">
            @csrf
            <label>Stupeň
                <select class="input mt-1" name="level" x-model="level">
                    <option value="1">1. upomínka</option>
                    <option value="2">2. upomínka</option>
                    <option value="3">3. upomínka</option>
                </select>
            </label>
            @foreach($previews as $level => $preview)
                @php($existing = $remindersByLevel->get($level))
                <div x-show="level === '{{ $level }}'" class="space-y-3">
                    <div class="rounded border bg-slate-50 p-4">
                        <p class="font-semibold">{{ $preview['subject'] }}</p>
                        <p class="mt-2 whitespace-pre-line text-sm">{{ $preview['body'] }}</p>
                    </div>
                    @if($existing?->status === 'prepared')
                        <p class="text-sm text-blue-700">Tato upomínka je připravena k odeslání.</p>
                    @elseif($existing?->status === 'sent')
                        <p class="text-sm text-emerald-700">Tato upomínka již byla odeslána a nebude odeslána podruhé.</p>
                    @elseif($existing?->status === 'failed')
                        <p class="text-sm text-red-700">Předchozí pokus selhal. Upomínku můžete bezpečně zkusit odeslat znovu.</p>
                    @elseif($existing?->status === 'sending')
                        <p class="text-sm text-amber-700">Tato upomínka se právě odesílá.</p>
                    @else
                        <p class="text-sm text-slate-500">Tento stupeň zatím nebyl připraven ani odeslán.</p>
                    @endif
                </div>
            @endforeach
            <button class="btn-primary">Potvrdit odeslání</button>
        </form>
    </div>
</x-layouts.app>
