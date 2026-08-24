<x-layouts.app title="Nastavení e-mailů">
    <div x-data="mailSettingsPreview({
        emailUrl: @js(route('invoice-email-settings.preview')),
        pdfUrl: @js(route('invoice-email-settings.pdf-preview')),
        csrf: @js(csrf_token()),
    })">
    <div class="mb-6">
        <p class="text-sm font-medium text-blue-700">Nastavení subjektu</p>
        <h1 class="mt-1 text-2xl font-bold">E-maily</h1>
        <p class="mt-2 text-sm text-slate-600">Šablona se použije pouze pro aktivní fakturační subjekt. SMTP From adresa zůstává řízená serverovou konfigurací.</p>
    </div>

    <form x-ref="form" method="POST" action="{{ route('invoice-email-settings.update') }}" enctype="multipart/form-data" class="card max-w-4xl space-y-7">
        @csrf
        @method('PUT')
        <div class="grid gap-5 md:grid-cols-2">
            <div><label for="sender_name">Jméno odesílatele *</label><input id="sender_name" name="sender_name" required maxlength="255" value="{{ old('sender_name', $setting->sender_name) }}">@error('sender_name')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label for="reply_to">Reply-To</label><input id="reply_to" name="reply_to" type="email" maxlength="255" value="{{ old('reply_to', $setting->reply_to) }}">@error('reply_to')<p class="field-error">{{ $message }}</p>@enderror</div>
        </div>
        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-2 font-semibold">Vzhled e-mailů</legend>
            <div><label for="template_style">E-mailový vzhled *</label><select id="template_style" name="template_style" required>@foreach($styles as $style)<option value="{{ $style->value }}" data-label="{{ $style->label() }}" @selected(old('template_style', $setting->template_style) === $style->value)>{{ $style->label() }} — {{ $style->description() }}</option>@endforeach</select>@error('template_style')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><button type="button" class="button-secondary" @click="openEmailPreview">Zobrazit náhled e-mailu</button></div>
            @if($logoAvailable)<div><p class="mb-2 text-sm font-medium text-slate-700">Aktuální logo</p><div class="inline-flex rounded-lg border border-slate-200 bg-white p-3"><img src="{{ route('invoice-email-settings.logo') }}" alt="Aktuální firemní logo" class="max-h-20 max-w-56 object-contain"></div></div>@endif
            <div><label for="logo">Nahrát nové logo</label><input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><p class="mt-1 text-xs text-slate-500">PNG, JPG nebo WEBP, nejvýše 2 MB a 2400 × 1200 px. Logo se vloží přímo do e-mailu a není veřejně dostupné.</p>@error('logo')<p class="field-error">{{ $message }}</p>@enderror</div>
            <input type="hidden" name="remove_logo" value="0">
            @if($setting->logo_path)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1"> Odebrat současné logo</label>@endif
        </fieldset>
        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-2 font-semibold">Vzhled PDF faktury</legend>
            <div><label for="pdf_style">PDF vzhled *</label><select id="pdf_style" name="pdf_style" required>@foreach($pdfStyles as $style)<option value="{{ $style->value }}" data-label="{{ $style->label() }}" @selected(old('pdf_style', $setting->pdf_style) === $style->value)>{{ $style->label() }} — {{ $style->description() }}</option>@endforeach</select>@error('pdf_style')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><button type="button" class="button-secondary" @click="openPdfPreview">Zobrazit náhled PDF</button></div>
            <p class="text-xs text-slate-500">Volba se použije jen pro nově generovaná nebo výslovně přegenerovaná PDF. Existující PDF dokumenty se nemění.</p>
        </fieldset>
        <div><label for="subject_template">Předmět faktury *</label><input id="subject_template" name="subject_template" required maxlength="255" value="{{ old('subject_template', $setting->subject_template) }}">@error('subject_template')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label for="body_template">Text e-mailu *</label><textarea id="body_template" name="body_template" rows="8" required maxlength="10000">{{ old('body_template', $setting->body_template) }}</textarea>@error('body_template')<p class="field-error">{{ $message }}</p>@enderror</div>
        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-2 font-semibold">Strukturovaný podpis</legend>
            @if($setting->signature && blank($setting->signature_name) && blank($setting->signature_text))<p class="rounded bg-amber-50 p-3 text-sm text-amber-900">Dokud nevyplníte strukturovaný podpis, zůstává použitý váš dosavadní text podpisu.</p>@endif
            <div class="grid gap-4 md:grid-cols-2">
                <div><label for="signature_name">Jméno / název</label><input id="signature_name" name="signature_name" maxlength="255" value="{{ old('signature_name', $setting->signature_name) }}">@error('signature_name')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label for="signature_role">Role / doplňkový řádek</label><input id="signature_role" name="signature_role" maxlength="255" value="{{ old('signature_role', $setting->signature_role) }}">@error('signature_role')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label for="signature_phone">Telefon</label><input id="signature_phone" name="signature_phone" maxlength="64" value="{{ old('signature_phone', $setting->signature_phone) }}">@error('signature_phone')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label for="signature_email">E-mail</label><input id="signature_email" name="signature_email" type="email" maxlength="255" value="{{ old('signature_email', $setting->signature_email) }}">@error('signature_email')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="md:col-span-2"><label for="signature_web">Web</label><input id="signature_web" name="signature_web" type="url" maxlength="500" placeholder="https://…" value="{{ old('signature_web', $setting->signature_web) }}">@error('signature_web')<p class="field-error">{{ $message }}</p>@enderror</div>
            </div>
            <div><label for="signature_text">Text podpisu</label><textarea id="signature_text" name="signature_text" rows="3" maxlength="2000">{{ old('signature_text', $setting->signature_text) }}</textarea><p class="mt-1 text-xs text-slate-500">Pouze prostý text. Odstavce a zalomení řádků budou zachovány.</p>@error('signature_text')<p class="field-error">{{ $message }}</p>@enderror</div>
        </fieldset>
        <div class="rounded-lg bg-slate-50 p-4 text-sm">
            <p class="font-semibold">Bezpečné placeholdery</p>
            <p class="mt-2 flex flex-wrap gap-2">@foreach($placeholders as $placeholder)<code class="rounded bg-white px-2 py-1">{{ $placeholder }}</code>@endforeach</p>
            <p class="mt-2 text-slate-600">Jiné výrazy ani PHP/Blade kód se nevyhodnocují.</p>
        </div>
        <div class="space-y-3">
            <label class="flex items-center gap-3"><input type="hidden" name="attach_pdf" value="0"><input name="attach_pdf" type="checkbox" value="1" @checked(old('attach_pdf', $setting->attach_pdf))> Přiložit PDF jako přílohu e-mailu</label>
            <label class="flex items-center gap-3"><input type="hidden" name="include_web_invoice" value="0"><input name="include_web_invoice" type="checkbox" value="1" @checked(old('include_web_invoice', $setting->include_web_invoice))> Vložit aktivní Webfakturu</label>
        </div>
        <div class="flex justify-end"><button class="button-primary" type="submit">Uložit nastavení e-mailů</button></div>
    </form>

    <template x-if="previewOpen">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-2 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="design-preview-title" @keydown.escape.window="closePreview" @click.self="closePreview">
        <section class="flex h-[94vh] w-[96vw] max-w-[1500px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:h-[90vh] sm:w-[94vw]">
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
                <div><h2 id="design-preview-title" class="text-lg font-bold sm:text-xl" x-text="previewTitle"></h2><p class="text-xs text-slate-500 sm:text-sm">Ukázková data bez uložení nebo odeslání.</p></div>
                <button x-ref="previewClose" type="button" class="button-secondary shrink-0" @click="closePreview">Zavřít</button>
            </header>
            <div class="relative min-h-0 flex-1 overflow-hidden bg-slate-100 p-2 sm:p-4">
                <div x-show="emailLoading || pdfLoading" class="flex h-full items-center justify-center text-sm font-medium text-slate-600" role="status">Načítám náhled…</div>
                <div x-show="emailError || pdfError" class="flex h-full items-center justify-center"><p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" x-text="emailError || pdfError"></p></div>
                <template x-if="previewType === 'email' && emailHtml && !emailError"><iframe :srcdoc="emailHtml" title="Náhled e-mailové šablony" class="h-full w-full rounded-lg border border-slate-200 bg-white"></iframe></template>
                <template x-if="previewType === 'pdf' && pdfHtml && !pdfError"><iframe :srcdoc="pdfHtml" title="Náhled PDF šablony" class="h-full w-full rounded-lg border border-slate-200 bg-slate-200"></iframe></template>
            </div>
        </section>
    </div>
    </template>
    </div>
</x-layouts.app>
