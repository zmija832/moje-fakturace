<x-layouts.app :title="'Odeslat fakturu '.$invoice->document_number">
    <div class="mx-auto max-w-2xl">
        <div class="mb-6"><p class="text-sm font-medium text-blue-700">Faktura {{ $invoice->document_number }}</p><h1 class="mt-1 text-2xl font-bold">Odeslat e-mailem</h1><p class="mt-2 text-sm text-slate-600">Příjemce je předvyplněn z neměnného snapshotu faktury. Jednorázová změna se neuloží ke klientovi.</p></div>
        <form method="POST" action="{{ route('invoices.email.send',$invoice->uuid) }}" class="card space-y-5">
            @csrf
            <input type="hidden" name="send_correlation_uuid" value="{{ $sendCorrelationUuid }}">
            <div><label for="recipient_email" class="label">E-mail příjemce</label><input id="recipient_email" class="input" type="email" name="recipient_email" required maxlength="255" value="{{ old('recipient_email',$revision->customerSnapshot->email) }}">@error('recipient_email')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="recipient_name" class="label">Jméno příjemce</label><input id="recipient_name" class="input" name="recipient_name" maxlength="255" value="{{ old('recipient_name',$revision->customerSnapshot->display_name) }}"></div>
            <div><label for="subject" class="label">Vlastní předmět (volitelné)</label><input id="subject" class="input" name="subject" maxlength="255" value="{{ old('subject') }}"></div>
            <div><label for="message" class="label">Krátká zpráva (volitelné)</label><textarea id="message" class="input" name="message" maxlength="2000" rows="5">{{ old('message') }}</textarea><p class="mt-1 text-xs text-slate-500">Text se bezpečně escapuje; vlastní HTML není povoleno.</p></div>
            <div class="rounded-lg bg-slate-50 p-3 text-sm">PDF bude získáno z privátního úložiště nebo bezpečně vygenerováno. Odeslání je synchronní a může chvíli trvat.</div>
            <div class="flex gap-3"><button class="button-primary" type="submit">Odeslat fakturu</button><a class="button-secondary" href="{{ route('invoices.show',$invoice->uuid) }}">Zrušit</a></div>
        </form>
    </div>
</x-layouts.app>
