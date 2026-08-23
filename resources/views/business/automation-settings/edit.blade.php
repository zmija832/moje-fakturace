<x-layouts.app title="Upomínky a automatizace">
    <p class="text-sm font-medium text-blue-700">Nastavení</p>
    <h1 class="text-3xl font-bold">Upomínky a automatizace</h1>

    <form method="POST" action="{{ route('automation-settings.update') }}" class="card mt-6 space-y-6">
        @csrf
        @method('PUT')

        <input type="hidden" name="reminders_enabled" value="0">
        <label class="flex gap-2">
            <input type="checkbox" name="reminders_enabled" value="1" @checked(old('reminders_enabled', $setting->reminders_enabled))>
            Automatické upomínky
        </label>

        <label>
            Režim
            <select class="input mt-1" name="reminder_mode">
                <option value="prepare" @selected(old('reminder_mode', $setting->reminder_mode) === 'prepare')>Pouze připravit</option>
                <option value="send" @selected(old('reminder_mode', $setting->reminder_mode) === 'send')>Automaticky odeslat</option>
            </select>
        </label>

        @foreach ([1, 2, 3] as $level)
            <fieldset class="space-y-3 rounded border p-4">
                <legend class="font-bold">{{ $level }}. upomínka</legend>
                <label>
                    Dnů po splatnosti
                    <input class="input mt-1" type="number" name="reminder_day_{{ $level }}" value="{{ old('reminder_day_'.$level, $setting->{'reminder_day_'.$level}) }}">
                </label>
                <label>
                    Předmět
                    <input class="input mt-1" name="reminder_subject_{{ $level }}" value="{{ old('reminder_subject_'.$level, $setting->{'reminder_subject_'.$level}) }}">
                </label>
                <label>
                    Text
                    <textarea class="input mt-1 min-h-32" name="reminder_body_{{ $level }}">{{ old('reminder_body_'.$level, $setting->{'reminder_body_'.$level}) }}</textarea>
                </label>
            </fieldset>
        @endforeach

        <p class="text-xs text-slate-500">Povolené placeholdery: {{ implode(', ', $reminderPlaceholders) }}</p>

        <fieldset class="space-y-4 rounded border p-4">
            <legend class="font-bold">Notifikace přijatých plateb</legend>

            <input type="hidden" name="notify_admin_when_paid" value="0">
            <label class="flex gap-2">
                <input type="checkbox" name="notify_admin_when_paid" value="1" @checked(old('notify_admin_when_paid', $setting->notify_admin_when_paid))>
                Informovat administrátora o přijatých platbách e-mailem a v aplikaci
            </label>

            <input type="hidden" name="notify_customer_when_paid" value="0">
            <label class="flex gap-2">
                <input type="checkbox" name="notify_customer_when_paid" value="1" @checked(old('notify_customer_when_paid', $setting->notify_customer_when_paid))>
                Poslat zákazníkovi potvrzení přijaté platby
            </label>

            @foreach ([
                ['Částečná úhrada', 'partial_subject', 'partial_body'],
                ['Úplná úhrada', 'paid_subject', 'paid_body'],
                ['Přeplatek', 'overpaid_subject', 'overpaid_body'],
            ] as [$label, $subjectField, $bodyField])
                <div class="space-y-3 rounded border border-slate-200 p-4">
                    <h2 class="font-semibold">{{ $label }}</h2>
                    <label>
                        Předmět
                        <input class="input mt-1" name="{{ $subjectField }}" value="{{ old($subjectField, $setting->{$subjectField}) }}">
                    </label>
                    <label>
                        Úvodní text
                        <textarea class="input mt-1 min-h-24" name="{{ $bodyField }}">{{ old($bodyField, $setting->{$bodyField}) }}</textarea>
                    </label>
                </div>
            @endforeach

            <p class="text-xs text-slate-500">Povolené placeholdery: {{ implode(', ', $paidPlaceholders) }}</p>
        </fieldset>

        @if ($errors->any())
            <div class="rounded bg-red-50 p-3 text-red-800">{{ implode(' ', array_unique($errors->all())) }}</div>
        @endif

        <button class="btn-primary">Uložit</button>
    </form>
</x-layouts.app>
