@props(['invoice'])
@php
    $previewUrl = route('invoices.quick-preview', $invoice->uuid);
    $previewTitle = $invoice->document_number ?? 'Návrh';
@endphp

<div class="flex flex-col items-end gap-1 whitespace-nowrap text-sm">
    <a class="font-semibold text-blue-700 hover:underline" href="{{ route('invoices.show', $invoice->uuid) }}">Detail</a>
    <div
        class="relative"
        data-preview-hover-zone
        @mouseenter="schedulePreview({{ Illuminate\Support\Js::from($previewUrl) }}, {{ Illuminate\Support\Js::from($previewTitle) }}, $event.currentTarget.querySelector('[data-preview-trigger]'))"
        @mouseleave="scheduleClosePreview"
        @focusin="schedulePreview({{ Illuminate\Support\Js::from($previewUrl) }}, {{ Illuminate\Support\Js::from($previewTitle) }}, $event.currentTarget.querySelector('[data-preview-trigger]'))"
        @focusout="scheduleClosePreview"
    >
        <button
            data-preview-trigger
            type="button"
            class="text-slate-600 hover:text-blue-700 hover:underline"
            @click="activatePreview({{ Illuminate\Support\Js::from($previewUrl) }}, {{ Illuminate\Support\Js::from($previewTitle) }}, $event.currentTarget)"
        >Náhled</button>

        <div
            x-cloak
            x-show="desktopOpen && currentUrl === {{ Illuminate\Support\Js::from($previewUrl) }}"
            class="absolute right-full top-0 z-50 hidden max-h-[80vh] w-[min(44rem,calc(100vw-5rem))] overflow-y-auto rounded-2xl border border-slate-200 bg-white text-left whitespace-normal shadow-2xl lg:block"
            role="dialog"
            :aria-label="`Rychlý náhled ${title}`"
        >
            <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-3">
                <h2 class="font-bold" x-text="`Rychlý náhled ${title}`"></h2>
                <span class="text-xs text-slate-500">Přejeďte mimo náhled pro zavření</span>
            </div>
            <div x-show="loading" class="p-8 text-center text-sm text-slate-500">Načítám náhled…</div>
            <div x-show="error" class="p-8 text-center text-sm text-red-700" x-text="error"></div>
            <div x-show="html" x-html="html"></div>
        </div>
    </div>
    @can('duplicate', $invoice)
        <a class="text-slate-600 hover:text-blue-700 hover:underline" href="{{ route('invoices.duplicate-form', $invoice->uuid) }}">Duplikovat</a>
    @endcan
</div>
