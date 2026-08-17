<x-layouts.app title="Nastavení e-mailů">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Nastavení subjektu</p>
        <h1 class="mt-1 text-2xl font-bold">E-maily</h1>
        <p class="mt-2 text-sm text-slate-600">Šablona se použije pouze pro aktivní fakturační subjekt. SMTP From adresa zůstává řízená serverovou konfigurací.</p>
    </div>

    <form method="POST" action="{{ route('invoice-email-settings.update') }}" class="card max-w-4xl space-y-5">
        @csrf
        @method('PUT')
        <div class="grid gap-5 md:grid-cols-2">
            <div><label for="sender_name">Jméno odesílatele *</label><input id="sender_name" name="sender_name" required maxlength="255" value="{{ old('sender_name', $setting->sender_name) }}">@error('sender_name')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="reply_to">Reply-To</label><input id="reply_to" name="reply_to" type="email" maxlength="255" value="{{ old('reply_to', $setting->reply_to) }}">@error('reply_to')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
        <div><label for="subject_template">Předmět faktury *</label><input id="subject_template" name="subject_template" required maxlength="255" value="{{ old('subject_template', $setting->subject_template) }}">@error('subject_template')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="body_template">Text e-mailu *</label><textarea id="body_template" name="body_template" rows="8" required maxlength="10000">{{ old('body_template', $setting->body_template) }}</textarea>@error('body_template')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="signature">Podpis</label><textarea id="signature" name="signature" rows="4" maxlength="5000">{{ old('signature', $setting->signature) }}</textarea>@error('signature')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div class="rounded-lg bg-slate-50 p-4 text-sm">
            <p class="font-semibold">Bezpečné placeholdery</p>
            <p class="mt-2 flex flex-wrap gap-2">@foreach($placeholders as $placeholder)<code class="rounded bg-white px-2 py-1">{{ $placeholder }}</code>@endforeach</p>
            <p class="mt-2 text-slate-600">Jiné výrazy ani PHP/Blade kód se nevyhodnocují.</p>
        </div>
        <div class="space-y-3">
            <label class="flex items-center gap-3"><input type="hidden" name="attach_pdf" value="0"><input name="attach_pdf" type="checkbox" value="1" @checked(old('attach_pdf', $setting->attach_pdf))> Připojit aktuální PDF</label>
            <label class="flex items-center gap-3"><input type="hidden" name="include_web_invoice" value="0"><input name="include_web_invoice" type="checkbox" value="1" @checked(old('include_web_invoice', $setting->include_web_invoice))> Vložit aktivní Webfakturu</label>
        </div>
        <div class="flex justify-end"><button class="button-primary" type="submit">Uložit nastavení e-mailů</button></div>
    </form>
</x-layouts.app>