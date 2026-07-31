@php
    $isActive = (bool) old('is_active', $sequence->is_active);
    $isUsed = $sequence->exists && $sequence->allocations_count > 0;
@endphp

@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <p class="font-semibold">Formulář se nepodařilo uložit.</p>
        <p class="mt-1">Opravte označená pole a odešlete jej znovu.</p>
    </div>
@endif

@if ($isUsed)
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        Řada již přidělila číslo. Typ dokladu, prefix, suffix, rok, počet číslic,
        počáteční číslo a resetování jsou historicky uzamčené. Pro jiný formát vytvořte novou řadu.
    </div>
@endif

<form method="POST" action="{{ $action }}" class="space-y-6"
      x-data="{
        prefix: @js(old('prefix', $sequence->prefix)),
        suffix: @js(old('suffix', $sequence->suffix)),
        yearFormat: @js(old('year_format', $sequence->year_format?->value)),
        digits: @js((int) old('sequence_digits', $sequence->sequence_digits)),
        start: @js((string) old('start_number', $sequence->start_number)),
        preview() {
            const year = this.yearFormat === 'yyyy' ? @js(today()->format('Y')) : (this.yearFormat === 'yy' ? @js(today()->format('y')) : '');
            const width = Math.max(1, Number(this.digits) || 1);
            return this.prefix + year + String(this.start || '1').padStart(width, '0') + this.suffix;
        }
      }">
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Základní údaje</h2>
            <p class="mt-1 text-sm text-slate-600">Typ budoucího dokladu a uživatelský název řady.</p>
        </div>
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label for="document_type">Typ dokladu <span class="text-red-700">*</span></label>
                <select id="document_type" name="document_type" required @error('document_type') aria-invalid="true" @enderror>
                    @foreach ($documentTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('document_type', $sequence->document_type?->value) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('document_type') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="name">Název řady <span class="text-red-700">*</span></label>
                <input id="name" name="name" value="{{ old('name', $sequence->name) }}" maxlength="255" required autofocus
                       @error('name') aria-invalid="true" @enderror>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </section>

    <section class="card">
        <div class="mb-5">
            <h2 class="text-lg font-bold">Formát čísla</h2>
            <p class="mt-1 text-sm text-slate-600">
                Výsledek je prefix + rok + pořadové číslo + suffix. Aplikace nevkládá žádné skryté oddělovače.
            </p>
        </div>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="prefix">Prefix</label>
                <input id="prefix" name="prefix" maxlength="64" value="{{ old('prefix', $sequence->prefix) }}" x-model="prefix"
                       @error('prefix') aria-invalid="true" @enderror>
                @error('prefix') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="year_format">Formát roku <span class="text-red-700">*</span></label>
                <select id="year_format" name="year_format" required x-model="yearFormat">
                    @foreach ($yearFormats as $value => $label)
                        <option value="{{ $value }}" @selected(old('year_format', $sequence->year_format?->value) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('year_format') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="sequence_digits">Počet číslic <span class="text-red-700">*</span></label>
                <input id="sequence_digits" name="sequence_digits" type="number" min="1" max="12" required
                       value="{{ old('sequence_digits', $sequence->sequence_digits) }}" x-model.number="digits">
                @error('sequence_digits') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="suffix">Suffix</label>
                <input id="suffix" name="suffix" maxlength="64" value="{{ old('suffix', $sequence->suffix) }}" x-model="suffix">
                @error('suffix') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-5 rounded-xl bg-blue-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Živý náhled</p>
            <p class="mt-1 break-all font-mono text-lg font-bold" x-text="preview()">{{ $serverPreview }}</p>
            <p class="mt-1 text-xs text-slate-600">Autoritativní číslo vždy vypočítá server při alokaci.</p>
        </div>
    </section>

    <section class="card">
        <div class="mb-5"><h2 class="text-lg font-bold">Čítač a stav</h2></div>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="start_number">Počáteční číslo <span class="text-red-700">*</span></label>
                <input id="start_number" name="start_number" type="number" min="1" max="999999999999" required
                       value="{{ old('start_number', $sequence->start_number) }}" x-model="start">
                @error('start_number') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="reset_period">Resetování <span class="text-red-700">*</span></label>
                <select id="reset_period" name="reset_period" required>
                    @foreach ($resetPeriods as $value => $label)
                        <option value="{{ $value }}" @selected(old('reset_period', $sequence->reset_period?->value) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('reset_period') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="sort_order">Pořadí <span class="text-red-700">*</span></label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="65535" required
                       value="{{ old('sort_order', $sequence->sort_order) }}">
                @error('sort_order') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-start gap-3 pt-7">
                <input id="is_active" name="is_active" type="checkbox" value="1" class="mt-0.5 h-4 w-4" @checked($isActive)>
                <div><label for="is_active">Aktivní řada</label><p class="mt-1 text-xs text-slate-500">Pouze aktivní řada může být výchozí a přidělovat čísla.</p></div>
            </div>
        </div>
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ $sequence->exists ? route('document-sequences.show', $sequence->uuid) : route('document-sequences.index') }}" class="button-secondary">Zrušit</a>
        <button type="submit" class="button-primary">{{ $submitLabel }}</button>
    </div>
</form>
