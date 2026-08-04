<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>Faktura {{ $document['document_number'] }}</title>
    <style>
        @page { margin: 18mm; }
        body { color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { font-size: 24px; margin: 0; } h2 { border-bottom: 1px solid #ccd3df; font-size: 13px; margin: 18px 0 7px; padding-bottom: 4px; }
        .muted { color: #5b6474; } .right { text-align: right; } .total { font-size: 17px; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; } th, td { border-bottom: 1px solid #dfe4ec; padding: 5px 4px; vertical-align: top; } th { background: #eef2f7; text-align: left; }
        .parties td { border: 0; padding: 0 14px 0 0; width: 50%; } .summary { margin-left: auto; width: 52%; }
        .qr { height: 120px; width: 120px; } .notice { background: #eef6ff; margin: 10px 0; padding: 8px; }
        @media print { .screen-only { display: none; } }
    </style>
</head>
<body>
@if(!$archival)<p class="notice screen-only"><strong>Tiskový náhled.</strong> Nejde o archivní PDF dokument.</p>@endif
<table><tr><td style="border:0"><h1>Faktura</h1><div class="muted">Vystavená</div></td><td class="right" style="border:0"><strong>{{ $document['document_number'] }}</strong><br>{{ $document['currency'] }}</td></tr></table>
<table class="parties"><tr><td><h2>Dodavatel</h2><strong>{{ $document['supplier']['name'] }}</strong>@if($document['supplier']['additional_name'])<br>{{ $document['supplier']['additional_name'] }}@endif<br>{{ $document['supplier']['address'] }}<br>IČO: {{ $document['supplier']['registration_number'] }}@if($document['supplier']['tax_id'])<br>DIČ: {{ $document['supplier']['tax_id'] }}@endif @if($document['supplier']['vat_id'])<br>IČ DPH: {{ $document['supplier']['vat_id'] }}@endif @if($document['supplier']['email'])<br>{{ $document['supplier']['email'] }}@endif @if($document['supplier']['phone'])<br>{{ $document['supplier']['phone'] }}@endif</td><td><h2>Odběratel</h2><strong>{{ $document['customer']['name'] }}</strong>@if($document['customer']['company_name'] && $document['customer']['company_name'] !== $document['customer']['name'])<br>{{ $document['customer']['company_name'] }}@endif<br>{{ $document['customer']['address'] }}@if($document['customer']['registration_number'])<br>IČO: {{ $document['customer']['registration_number'] }}@endif @if($document['customer']['tax_id'])<br>DIČ: {{ $document['customer']['tax_id'] }}@endif @if($document['customer']['vat_id'])<br>IČ DPH: {{ $document['customer']['vat_id'] }}@endif @if($document['customer']['email'])<br>{{ $document['customer']['email'] }}@endif @if($document['customer']['contact_person'])<br>Kontakt: {{ $document['customer']['contact_person'] }}@endif</td></tr></table>
@if($document['customer']['delivery_address'])<p><strong>Dodací adresa:</strong> {{ $document['customer']['delivery_address'] }}</p>@endif
<h2>Údaje dokladu</h2>
<table><tr><td>Datum vystavení<br><strong>{{ $document['issued_on'] }}</strong></td><td>DUZP<br><strong>{{ $document['taxable_supply_on'] }}</strong></td><td>Splatnost<br><strong>{{ $document['due_on'] }}</strong></td><td>Variabilní symbol<br><strong>{{ $document['variable_symbol'] ?: '—' }}</strong></td><td>Úhrada<br><strong>{{ $document['payment_method'] }}</strong></td></tr></table>
@if($document['bank_account'])<p><strong>Bankovní účet:</strong> {{ $document['bank_account']['domestic'] }} @if($document['bank_account']['iban']) · IBAN {{ $document['bank_account']['iban'] }}@endif @if($document['bank_account']['bic']) · BIC {{ $document['bank_account']['bic'] }}@endif</p>@endif
@if($document['intro'])<p>{!! nl2br(e($document['intro'])) !!}</p>@endif
<h2>Položky</h2>
<table><thead><tr><th>Popis</th><th class="right">Množství</th><th class="right">Cena bez DPH</th><th class="right">Sleva</th><th class="right">Základ</th><th>DPH / režim</th><th class="right">Celkem</th></tr></thead><tbody>
@foreach($document['items'] as $item)<tr><td>{{ $item['description'] }}</td><td class="right">{{ $item['quantity'] }} {{ $item['unit'] }}</td><td class="right">{{ $item['unit_price'] }} {{ $document['currency'] }}</td><td class="right">{{ $item['discount'] }} {{ $document['currency'] }}</td><td class="right">{{ $item['tax_base'] }} {{ $document['currency'] }}</td><td>{{ $item['tax_label'] }}<br>{{ $item['vat_amount'] }} {{ $document['currency'] }}</td><td class="right">{{ $item['total'] }} {{ $document['currency'] }}</td></tr>@endforeach
</tbody></table>
<h2>Souhrn DPH</h2><table><thead><tr><th>Režim</th><th class="right">Základ</th><th class="right">DPH</th><th class="right">Celkem</th></tr></thead><tbody>@foreach($document['vat_summaries'] as $row)<tr><td>{{ $row['tax_label'] }}</td><td class="right">{{ $row['tax_base'] }} {{ $document['currency'] }}</td><td class="right">{{ $row['vat_amount'] }} {{ $document['currency'] }}</td><td class="right">{{ $row['total'] }} {{ $document['currency'] }}</td></tr>@endforeach</tbody></table>
<table class="summary"><tr><td>Mezisoučet před slevou</td><td class="right">{{ $document['totals']['subtotal_before_discount'] }} {{ $document['currency'] }}</td></tr><tr><td>Sleva celkem</td><td class="right">{{ $document['totals']['discount_total'] }} {{ $document['currency'] }}</td></tr><tr><td>Základ daně</td><td class="right">{{ $document['totals']['tax_base_total'] }} {{ $document['currency'] }}</td></tr><tr><td>DPH</td><td class="right">{{ $document['totals']['vat_total'] }} {{ $document['currency'] }}</td></tr><tr><td>Celkem před zaokrouhlením</td><td class="right">{{ $document['totals']['total_before_rounding'] }} {{ $document['currency'] }}</td></tr><tr><td>Zaokrouhlení</td><td class="right">{{ $document['totals']['rounding_adjustment'] }} {{ $document['currency'] }}</td></tr><tr><td class="total">Celkem</td><td class="right total">{{ $document['totals']['grand_total'] }} {{ $document['currency'] }}</td></tr></table>
@if($document['qr']['available'])<h2>QR Platba</h2><img class="qr" alt="QR Platba" src="{{ $document['qr']['svg_data_uri'] }}"><div class="muted">Údaje byly vytvořeny podle SPD 1.0 z bankovního snapshotu faktury.</div>@endif
@if($document['note'])<p><strong>Poznámka:</strong><br>{!! nl2br(e($document['note'])) !!}</p>@endif
@if($document['outro'])<p>{!! nl2br(e($document['outro'])) !!}</p>@endif
</body></html>
