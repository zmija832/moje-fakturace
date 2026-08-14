<?php

namespace App\Services\Business;

use App\Domain\Invoices\Exceptions\InvoiceNotIssuedForDelivery;
use App\Domain\Invoices\InvoiceDecimal;
use App\Domain\Invoices\InvoiceDocumentViewModel;
use App\Enums\InvoiceStatus;
use App\Enums\VatTaxType;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;

class InvoiceDocumentViewModelFactory
{
    public function __construct(private readonly InvoiceQrPaymentService $qrPayments) {}

    public function make(Invoice $invoice): InvoiceDocumentViewModel
    {
        if ($invoice->status !== InvoiceStatus::Issued || $invoice->issuedRevision === null) {
            throw InvoiceNotIssuedForDelivery::create();
        }
        $revision = $invoice->issuedRevision;
        $revision->loadMissing(['supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot', 'items.vatSnapshot', 'vatSummaries']);
        $qr = $this->qrPayments->create($invoice, $revision);
        $isNonPayer = $revision->items->isNotEmpty()
            && $revision->items->every(fn ($item): bool => $item->vatSnapshot->tax_type === VatTaxType::NonPayer);

        return new InvoiceDocumentViewModel([
            'template_version' => 'invoice-v1',
            'locale' => 'cs',
            'document_number' => $invoice->document_number,
            'status_label' => 'Vystavená',
            'issued_on' => $this->date($revision->issued_on),
            'taxable_supply_on' => $this->date($revision->taxable_supply_on),
            'due_on' => $this->date($revision->due_on),
            'variable_symbol' => $revision->variable_symbol,
            'payment_method' => $revision->payment_method->label(),
            'currency' => $revision->currency,
            'is_non_payer' => $isNonPayer,
            'supplier' => $this->supplier($revision),
            'customer' => $this->customer($revision),
            'bank_account' => $this->bankAccount($revision),
            'items' => $revision->items->map(fn ($item): array => [
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $this->decimal($item->quantity, 4),
                'unit' => $item->unit,
                'unit_price' => $this->money($item->unit_price),
                'discount' => $this->money(InvoiceDecimal::add($item->line_discount_amount, $item->invoice_discount_amount)),
                'tax_base' => $this->money($item->line_net_amount),
                'tax_label' => $item->vatSnapshot->tax_type->label().($item->vatSnapshot->percentage === null ? '' : ' '.$this->decimal($item->vatSnapshot->percentage, 2).' %'),
                'vat_amount' => $this->money($item->vat_amount),
                'total' => $this->money($item->line_total_amount),
            ])->all(),
            'vat_summaries' => $revision->vatSummaries->map(fn ($summary): array => [
                'tax_label' => $summary->tax_type->label().($summary->percentage === null ? '' : ' '.$this->decimal($summary->percentage, 2).' %'),
                'tax_base' => $this->money($summary->tax_base),
                'vat_amount' => $this->money($summary->vat_amount),
                'total' => $this->money($summary->total_amount),
            ])->all(),
            'totals' => [
                'subtotal_before_discount' => $this->money($revision->subtotal_before_discount),
                'discount_total' => $this->money($revision->discount_total),
                'tax_base_total' => $this->money($revision->tax_base_total),
                'vat_total' => $this->money($revision->vat_total),
                'total_before_rounding' => $this->money($revision->total_before_rounding),
                'rounding_adjustment' => $this->money($revision->rounding_adjustment),
                'grand_total' => $this->money($revision->grand_total),
                'has_discount' => InvoiceDecimal::compare($revision->discount_total, '0') !== 0,
                'has_rounding' => InvoiceDecimal::compare($revision->rounding_adjustment, '0') !== 0,
            ],
            'intro' => $revision->supplierSnapshot->invoice_intro,
            'outro' => $revision->supplierSnapshot->invoice_outro,
            'note' => $revision->note,
            'qr' => ['available' => $qr->available, 'payload' => $qr->payload, 'svg_data_uri' => $qr->svgDataUri, 'reason' => $qr->reason],
        ]);
    }

    private function supplier(InvoiceRevision $revision): array
    {
        $s = $revision->supplierSnapshot;

        return ['name' => $s->legal_name, 'additional_name' => $s->additional_name, 'registration_number' => $s->registration_number, 'tax_id' => $s->tax_id, 'vat_id' => $s->vat_id, 'address' => $this->address($s), 'email' => $s->email, 'phone' => $s->phone, 'is_vat_payer' => $s->is_vat_payer];
    }

    private function customer(InvoiceRevision $revision): array
    {
        $c = $revision->customerSnapshot;

        return ['name' => $c->display_name, 'company_name' => $c->company_name, 'registration_number' => $c->registration_number, 'tax_id' => $c->tax_id, 'vat_id' => $c->vat_id, 'address' => $this->address($c), 'delivery_address' => $c->delivery_street ? trim(implode(', ', array_filter([$c->delivery_name, trim($c->delivery_street.' '.($c->delivery_house_number ?? '')), trim(($c->delivery_postal_code ?? '').' '.($c->delivery_city ?? '')), $c->delivery_country_code]))) : null, 'email' => $c->email, 'contact_person' => $c->contact_person];
    }

    private function bankAccount(InvoiceRevision $revision): ?array
    {
        $a = $revision->bankAccountSnapshot;

        return $a ? ['name' => $a->name, 'domestic' => trim(($a->domestic_prefix ? $a->domestic_prefix.'-' : '').($a->domestic_account_number ?? '').($a->bank_code ? '/'.$a->bank_code : '')), 'iban' => $a->iban, 'bic' => $a->bic, 'currency' => $a->currency] : null;
    }

    private function address(object $value): string
    {
        return trim(implode(', ', array_filter([trim($value->street.' '.($value->house_number ?? '').($value->orientation_number ? '/'.$value->orientation_number : '')), trim($value->postal_code.' '.$value->city), $value->country_code])));
    }

    private function date(object $date): string
    {
        return $date->format('j. n. Y');
    }

    private function money(mixed $value): string
    {
        return InvoiceDecimal::format($value, 2);
    }

    private function decimal(mixed $value, int $scale): string
    {
        return rtrim(rtrim(InvoiceDecimal::format($value, $scale), '0'), ',');
    }
}
