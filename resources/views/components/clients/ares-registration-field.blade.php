@props([
    'id',
    'value' => '',
    'lookup',
    'loading',
    'disabled',
    'show' => null,
    'errorExpression' => null,
    'serverError' => false,
    'inputRef' => null,
])

<div
    class="rounded-xl border border-sky-200 bg-sky-50/70 p-4 md:col-span-2"
    data-ares-registration-field
    @if($show) x-cloak x-show="{{ $show }}" @endif
>
    <div class="mb-3">
        <p class="font-semibold text-slate-900">Začněte IČO</p>
        <p class="mt-1 text-xs text-slate-600">Zadejte IČO a načtěte dostupné firemní údaje z ARES.</p>
    </div>
    <label for="{{ $id }}">IČO</label>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
        <input
            id="{{ $id }}"
            name="registration_number"
            inputmode="numeric"
            maxlength="32"
            value="{{ $value }}"
            @if($inputRef) x-ref="{{ $inputRef }}" @endif
            @if($serverError) @error('registration_number') aria-invalid="true" @enderror @endif
        >
        <button
            type="button"
            class="button-secondary shrink-0 whitespace-nowrap border-sky-300 bg-white"
            :disabled="{{ $disabled }}"
            @click="{{ $lookup }}"
            x-text="{{ $loading }} ? 'Načítám…' : 'Načíst z ARES'"
        ></button>
    </div>
    @if($serverError)
        @error('registration_number')<p class="field-error">{{ $message }}</p>@enderror
    @elseif($errorExpression)
        <p x-show="{{ $errorExpression }}" x-text="{{ $errorExpression }}" class="field-error"></p>
    @endif
</div>
