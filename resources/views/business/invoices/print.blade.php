@php
    $pdfStyle ??= \App\Enums\InvoicePdfStyle::Classic;
    $pdfTheme ??= $pdfStyle->theme();
    $archival ??= false;
    $logoDataUri ??= null;
@endphp
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>Faktura {{ $document['document_number'] }}</title>
    <style>
        @page { margin: {{ $pdfTheme['page_margin'] }}mm; }
        body { color: {{ $pdfTheme['primary'] }}; font-family: "DejaVu Sans", sans-serif; font-size: {{ $pdfTheme['font_size'] }}px; line-height: {{ $pdfStyle->value === 'compact' ? '1.28' : '1.45' }}; }
        h1 { font-size: {{ $pdfStyle->value === 'compact' ? '21' : '25' }}px; margin: 0; } h2 { border-bottom: 1px solid {{ $pdfTheme['border'] }}; color: {{ $pdfTheme['accent'] }}; font-size: 13px; margin: {{ $pdfTheme['section_margin'] }}px 0 7px; padding-bottom: 4px; }
        .muted { color: {{ $pdfTheme['muted'] }}; } .right { text-align: right; } .total { color: {{ $pdfTheme['accent'] }}; font-size: 18px; font-weight: bold; }
        .document-number { font-size: 18px; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; } th, td { border-bottom: 1px solid {{ $pdfTheme['border'] }}; padding: {{ $pdfTheme['cell_padding'] }}px 4px; vertical-align: top; } th { background: {{ $pdfTheme['soft'] }}; color: {{ $pdfTheme['primary'] }}; text-align: left; }
        thead { display: table-header-group; } tr { page-break-inside: avoid; }
        .parties td { border: 0; padding: 0 14px 0 0; width: 50%; } .summary { margin-left: auto; width: 52%; }
        .qr { height: {{ $pdfStyle->value === 'compact' ? '100' : '120' }}px; width: {{ $pdfStyle->value === 'compact' ? '100' : '120' }}px; } .notice { background: #eef6ff; margin: 10px 0; padding: 8px; }
        .non-payer { background: {{ $pdfTheme['soft'] }}; border: 1px solid {{ $pdfTheme['border'] }}; color: {{ $pdfTheme['primary'] }}; display: inline-block; font-weight: bold; margin-top: 8px; padding: 4px 8px; }
        .invoice-header td { background: {{ $pdfTheme['header'] }}; border: 0; color: {{ $pdfTheme['header_text'] }}; padding: {{ $pdfStyle->value === 'elegant' ? '15' : '11' }}px; }
        .invoice-header .muted { color: {{ $pdfTheme['header_text'] }}; opacity: .75; }
        .brand-logo { display: block; margin-bottom: 8px; max-height: 48px; max-width: 190px; width: auto; }
        .payment-highlight td { background: {{ $pdfTheme['soft'] }}; }
        .preview-watermark { color: #9a3412; font-size: 9px; font-weight: bold; text-align: center; }
        @media print { .screen-only { display: none; } }
    </style>
</head>
<body>
@php($nonPayer = $document['is_non_payer'])
@if(!$archival)<p class="notice screen-only"><strong>Tiskový náhled.</strong> Toto je HTML stránka pro tisk, nikoli uložený PDF soubor.</p>@endif
<table class="invoice-header"><tr><td>@if($logoDataUri)<img class="brand-logo" alt="Logo" src="{{ $logoDataUri }}">@endif<h1>Faktura</h1><div class="muted">Vystavená faktura</div>@if($nonPayer)<div class="non-payer">Neplátce DPH</div>@endif</td><td class="right"><div class="document-number">{{ $document['document_number'] }}</div><div>{{ $document['currency_label'] }}</div></td></tr></table>
@if($document['template_version'] === 'invoice-preview')<p class="preview-watermark">NÁHLED VZHLEDU – NENÍ ÚČETNÍ DOKLAD</p>@endif

<table class="parties"><tr><td><h2>Dodavatel</h2><strong>{{ $document['supplier']['name'] }}</strong>@if($document['supplier']['additional_name'])<br>{{ $document['supplier']['additional_name'] }}@endif<br>{{ $document['supplier']['address'] }}<br>IČO: {{ $document['supplier']['registration_number'] }}@if($document['supplier']['tax_id'])<br>DIČ: {{ $document['supplier']['tax_id'] }}@endif @if($document['supplier']['vat_id'])<br>IČ DPH: {{ $document['supplier']['vat_id'] }}@endif @if($document['supplier']['email'])<br>{{ $document['supplier']['email'] }}@endif @if($document['supplier']['phone'])<br>{{ $document['supplier']['phone'] }}@endif</td><td><h2>Odběratel</h2><strong>{{ $document['customer']['name'] }}</strong>@if($document['customer']['company_name'] && $document['customer']['company_name'] !== $document['customer']['name'])<br>{{ $document['customer']['company_name'] }}@endif<br>{{ $document['customer']['address'] }}@if($document['customer']['registration_number'])<br>IČO: {{ $document['customer']['registration_number'] }}@endif @if($document['customer']['tax_id'])<br>DIČ: {{ $document['customer']['tax_id'] }}@endif @if($document['customer']['vat_id'])<br>IČ DPH: {{ $document['customer']['vat_id'] }}@endif @if($document['customer']['email'])<br>{{ $document['customer']['email'] }}@endif @if($document['customer']['contact_person'])<br>Kontakt: {{ $document['customer']['contact_person'] }}@endif</td></tr></table>
@if($document['customer']['delivery_address'])<p><strong>Dodací adresa:</strong> {{ $document['customer']['delivery_address'] }}</p>@endif

<h2>Údaje dokladu</h2>
<table class="payment-highlight"><tr><td>Datum vystavení<br><strong>{{ $document['issued_on'] }}</strong></td><td>DUZP<br><strong>{{ $document['taxable_supply_on'] }}</strong></td><td>Splatnost<br><strong>{{ $document['due_on'] }}</strong></td><td>Variabilní symbol<br><strong>{{ $document['variable_symbol'] ?: '—' }}</strong></td><td>Způsob úhrady<br><strong>{{ $document['payment_method'] }}</strong></td></tr></table>
@if($document['bank_account'])<p><strong>Bankovní účet:</strong> {{ $document['bank_account']['domestic'] }} @if($document['bank_account']['iban']) · IBAN {{ $document['bank_account']['iban'] }}@endif @if($document['bank_account']['bic']) · BIC {{ $document['bank_account']['bic'] }}@endif</p>@endif
@if($document['intro'])<p>{!! nl2br(e($document['intro'])) !!}</p>@endif

<h2>Položky</h2>
@if($nonPayer)
    <table><thead><tr><th>Popis</th><th class="right">Množství</th><th class="right">Jednotková cena</th><th class="right">Sleva</th><th class="right">Celkem</th></tr></thead><tbody>
    @foreach($document['items'] as $item)<tr><td>{{ $item['description'] }}</td><td class="right">{{ $item['quantity'] }} {{ $item['unit'] }}</td><td class="right">{{ $item['unit_price'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $item['discount'] }} {{ $document['currency_label'] }}</td><td class="right"><strong>{{ $item['total'] }} {{ $document['currency_label'] }}</strong></td></tr>@endforeach
    </tbody></table>
@else
    <table><thead><tr><th>Popis</th><th class="right">Množství</th><th class="right">Cena bez DPH</th><th class="right">Sleva</th><th class="right">Základ</th><th>DPH / režim</th><th class="right">Celkem</th></tr></thead><tbody>
    @foreach($document['items'] as $item)<tr><td>{{ $item['description'] }}</td><td class="right">{{ $item['quantity'] }} {{ $item['unit'] }}</td><td class="right">{{ $item['unit_price'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $item['discount'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $item['tax_base'] }} {{ $document['currency_label'] }}</td><td>{{ $item['tax_label'] }}<br>{{ $item['vat_amount'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $item['total'] }} {{ $document['currency_label'] }}</td></tr>@endforeach
    </tbody></table>
    <h2>Souhrn DPH</h2>
    <table><thead><tr><th>Režim</th><th class="right">Základ</th><th class="right">DPH</th><th class="right">Celkem</th></tr></thead><tbody>@foreach($document['vat_summaries'] as $row)<tr><td>{{ $row['tax_label'] }}</td><td class="right">{{ $row['tax_base'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $row['vat_amount'] }} {{ $document['currency_label'] }}</td><td class="right">{{ $row['total'] }} {{ $document['currency_label'] }}</td></tr>@endforeach</tbody></table>
@endif

<table class="summary">
    <tr><td>Mezisoučet</td><td class="right">{{ $document['totals']['subtotal_before_discount'] }} {{ $document['currency_label'] }}</td></tr>
    @if($document['totals']['has_discount'])<tr><td>Sleva celkem</td><td class="right">{{ $document['totals']['discount_total'] }} {{ $document['currency_label'] }}</td></tr>@endif
    @unless($nonPayer)<tr><td>Základ daně</td><td class="right">{{ $document['totals']['tax_base_total'] }} {{ $document['currency_label'] }}</td></tr><tr><td>DPH</td><td class="right">{{ $document['totals']['vat_total'] }} {{ $document['currency_label'] }}</td></tr>@endunless
    @if($document['totals']['has_rounding'])<tr><td>Zaokrouhlení</td><td class="right">{{ $document['totals']['rounding_adjustment'] }} {{ $document['currency_label'] }}</td></tr>@endif
    <tr><td class="total">Celkem</td><td class="right total">{{ $document['totals']['grand_total'] }} {{ $document['currency_label'] }}</td></tr>
</table>
@if($document['qr']['available'])<h2>QR Platba</h2><img class="qr" alt="QR Platba" src="{{ $document['qr']['svg_data_uri'] }}"><div class="muted">QR Platba podle standardu SPD 1.0.</div>@endif
@if($document['note'])<p><strong>Poznámka:</strong><br>{!! nl2br(e($document['note'])) !!}</p>@endif
@if($document['outro'])<p>{!! nl2br(e($document['outro'])) !!}</p>@endif
</body>
</html>
