@props(['invoice', 'link' => null, 'url' => null])

<section id="invoice-public-link" class="card mt-6 scroll-mt-6" x-data="{ copied: false }">
    <h2 class="text-lg font-bold">Webfaktura</h2>
    <p class="mt-1 text-sm text-slate-600">Bezpečný veřejný odkaz pouze pro čtení vystavené faktury. Odkaz lze kdykoli zrušit nebo nahradit.</p>
    @if($link && $url)
        <div class="mt-4 rounded-xl bg-slate-50 p-4">
            <label for="public-invoice-url">Veřejná URL</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row"><input id="public-invoice-url" class="font-mono text-sm" readonly value="{{ $url }}"><a class="button-secondary" target="_blank" rel="noopener noreferrer" href="{{ $url }}">Otevřít</a><button class="button-secondary" type="button" @click="navigator.clipboard.writeText(document.getElementById('public-invoice-url').value).then(() => copied = true)">Kopírovat</button></div>
            <p class="mt-2 text-sm text-emerald-700" x-cloak x-show="copied">Odkaz byl zkopírován.</p>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('invoices.public-link.regenerate', $invoice->uuid) }}" onsubmit="return confirm('Původní veřejný odkaz okamžitě přestane fungovat. Pokračovat?')">@csrf<button class="button-secondary" type="submit">Vygenerovat nový odkaz</button></form>
            <form method="POST" action="{{ route('invoices.public-link.revoke', $invoice->uuid) }}" onsubmit="return confirm('Opravdu zrušit veřejný odkaz?')">@csrf @method('DELETE')<button class="button-secondary text-red-700" type="submit">Zrušit veřejný odkaz</button></form>
        </div>
    @elseif($link)
        <p class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">Uložený token odkazu nelze bezpečně načíst. Vygenerujte nový odkaz.</p>
        <form class="mt-3" method="POST" action="{{ route('invoices.public-link.regenerate', $invoice->uuid) }}">@csrf<button class="button-secondary" type="submit">Vygenerovat nový odkaz</button></form>
    @else
        <form class="mt-4" method="POST" action="{{ route('invoices.public-link.store', $invoice->uuid) }}">@csrf<button class="button-secondary" type="submit">Vytvořit Webfakturu</button></form>
    @endif
</section>
