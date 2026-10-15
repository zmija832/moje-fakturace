@props(['label'])
<span class="inline-flex max-w-full items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $label->color->badgeClasses() }}">
    <span class="truncate">{{ $label->name }}</span>
</span>
