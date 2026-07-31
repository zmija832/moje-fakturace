<x-layouts.app title="Nová číselná řada">
    <div class="mb-6"><p class="text-sm font-medium text-blue-700">Číselné řady</p><h1 class="mt-1 text-2xl font-bold">Nová číselná řada</h1></div>
    @include('business.document-sequences._form', [
        'action' => route('document-sequences.store'),
        'method' => 'POST',
        'submitLabel' => 'Vytvořit řadu',
    ])
</x-layouts.app>
