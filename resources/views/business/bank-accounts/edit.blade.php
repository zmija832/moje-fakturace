<x-layouts.app title="Upravit bankovní účet">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Bankovní účty</p>
        <h1 class="mt-1 text-2xl font-bold">Upravit bankovní účet</h1>
    </div>

    @include('business.bank-accounts._form', [
        'account' => $account,
        'currencies' => $currencies,
        'action' => route('bank-accounts.update', $account->uuid),
        'method' => 'PUT',
        'submitLabel' => 'Uložit účet',
    ])

    <section class="card mt-6">
        <h2 class="text-lg font-bold">Automatické párování plateb – Fio banka</h2>
        <p class="mt-2 text-sm text-slate-600">Token patří výhradně tomuto účtu ({{ $account->name }} · {{ $account->currency }}). Je uložen šifrovaně a nikdy se nezobrazuje zpět.</p>
        <form method="POST" action="{{ route('bank-accounts.fio.update', $account->uuid) }}" class="mt-5 space-y-4">
            @csrf @method('PUT')
            <label class="flex items-center gap-3"><input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $account->fioSetting?->is_enabled))> <span>Automaticky synchronizovat příchozí platby</span></label>
            <div>
                <label for="fio_token">API token {{ $account->fioSetting?->encrypted_token ? '(token je nastaven)' : '' }}</label>
                <input id="fio_token" name="token" type="password" autocomplete="new-password" placeholder="{{ $account->fioSetting?->encrypted_token ? '••••••••••••••••' : 'Vložte Fio API token' }}">
                <p class="mt-1 text-xs text-slate-500">Prázdné pole zachová již uložený token.</p>
            </div>
            <button class="button-primary" type="submit">Uložit Fio nastavení</button>
        </form>
        @if($account->fioSetting?->encrypted_token)
            <div class="mt-4 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('bank-accounts.fio.test', $account->uuid) }}">@csrf<button class="button-secondary" type="submit">Otestovat spojení</button></form>
                @if($account->fioSetting->is_enabled)<form method="POST" action="{{ route('bank-accounts.fio.sync', $account->uuid) }}">@csrf<button class="button-secondary" type="submit">Synchronizovat nyní</button></form>@endif
            </div>
            <dl class="mt-4 grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
                <div><dt>Poslední pokus</dt><dd>{{ $account->fioSetting->last_attempt_at?->format('d. m. Y H:i') ?? '—' }}</dd></div>
                <div><dt>Poslední úspěch</dt><dd>{{ $account->fioSetting->last_successful_sync_at?->format('d. m. Y H:i') ?? '—' }}</dd></div>
                <div><dt>Poslední chyba</dt><dd>{{ $account->fioSetting->last_error_at?->format('d. m. Y H:i') ?? '—' }}</dd></div>
            </dl>
        @endif
    </section>
</x-layouts.app>
