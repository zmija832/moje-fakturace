<x-layouts.app :title="'Upravit '.$sequence->name">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Číselné řady</p><h1 class="mt-1 text-2xl font-bold">Upravit {{ $sequence->name }}</h1></div>
    @include('business.document-sequences._form', [
        'action' => route('document-sequences.update', $sequence->uuid),
        'method' => 'PUT',
        'submitLabel' => 'Uložit řadu',
    ])
</x-layouts.app>
