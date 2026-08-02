<?php

namespace App\Services\Business;

use App\Domain\CompanySettings\CompanySettingOptions;
use App\Domain\Invoices\Exceptions\InvoiceNotReadyForIssue;
use App\Domain\Invoices\InvoiceCalculator;
use App\Domain\Invoices\InvoiceDecimal;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceRevision;
use Illuminate\Support\Collection;
use Throwable;

class InvoiceIssueReadinessValidator
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    public function validate(Invoice $invoice): InvoiceRevision
    {
        $revision = $invoice->currentRevision()->where('invoice_id', $invoice->id)->first();

        if ($revision === null) {
            throw InvoiceNotReadyForIssue::because('chybí aktuální revize');
        }

        $revision->load([
            'supplierSnapshot', 'customerSnapshot', 'bankAccountSnapshot',
            'vatSnapshots', 'items.vatSnapshot', 'vatSummaries',
        ]);

        if ($revision->supplierSnapshot === null || $revision->customerSnapshot === null) {
            throw InvoiceNotReadyForIssue::because('chybí snapshot dodavatele nebo odběratele');
        }
        if ($revision->items->isEmpty()) {
            throw InvoiceNotReadyForIssue::because('faktura nemá žádnou položku');
        }

        $currency = (string) $revision->getRawOriginal('currency');
        $paymentMethod = (string) $revision->getRawOriginal('payment_method');

        if (! array_key_exists($currency, CompanySettingOptions::CURRENCIES)) {
            throw InvoiceNotReadyForIssue::because('měna není podporována');
        }
        if (DefaultPaymentMethod::tryFrom($paymentMethod) === null) {
            throw InvoiceNotReadyForIssue::because('platební metoda není podporována');
        }
        if ($revision->getRawOriginal('taxable_supply_on') === null) {
            throw InvoiceNotReadyForIssue::because('chybí datum zdanitelného plnění');
        }
        if ((string) $revision->getRawOriginal('due_on') < (string) $revision->getRawOriginal('issued_on')) {
            throw InvoiceNotReadyForIssue::because('datum splatnosti předchází datu vystavení');
        }
        if ($paymentMethod === DefaultPaymentMethod::BankTransfer->value && $revision->bankAccountSnapshot === null) {
            throw InvoiceNotReadyForIssue::because('bankovní převod vyžaduje snapshot bankovního účtu');
        }

        $calculation = $this->recalculate($revision);
        $this->assertItems($revision, $calculation['items']);
        $this->assertSummaries($revision->vatSummaries, $calculation['summaries']);
        $this->assertTotals($revision, $calculation);

        if (InvoiceDecimal::compare((string) $revision->grand_total, '0') < 0) {
            throw InvoiceNotReadyForIssue::because('celková částka je záporná');
        }

        return $revision;
    }

    /** @return array<string, mixed> */
    private function recalculate(InvoiceRevision $revision): array
    {
        $rates = [];

        foreach ($revision->vatSnapshots as $snapshot) {
            $rates[$snapshot->uuid] = [
                'tax_type' => $snapshot->tax_type,
                'percentage' => $snapshot->percentage,
            ];
        }

        $items = [];

        foreach ($revision->items as $item) {
            if ($item->vatSnapshot === null || (int) $item->vatSnapshot->invoice_revision_id !== (int) $revision->id) {
                throw InvoiceNotReadyForIssue::because('položka nemá platný VAT snapshot aktuální revize');
            }
            $items[] = [
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type->value,
                'discount_value' => $item->discount_value,
                'vat_rate_uuid' => $item->vatSnapshot->uuid,
            ];
        }

        try {
            return $this->calculator->calculate(
                $items,
                $rates,
                ['type' => $revision->invoice_discount_type->value, 'value' => $revision->invoice_discount_value],
                $revision->currency === 'CZK' && $revision->payment_method === DefaultPaymentMethod::Cash ? 0 : 2,
            );
        } catch (Throwable) {
            throw InvoiceNotReadyForIssue::because('uložený výpočet nelze bezpečně přepočítat');
        }
    }

    /** @param list<array<string, mixed>> $calculated */
    private function assertItems(InvoiceRevision $revision, array $calculated): void
    {
        if ($revision->items->count() !== count($calculated)) {
            throw InvoiceNotReadyForIssue::because('počet přepočtených položek nesouhlasí');
        }

        $numeric = [
            'quantity', 'unit_price', 'discount_value', 'line_discount_amount',
            'invoice_discount_amount', 'unit_price_after_discount', 'line_net_amount',
            'vat_amount', 'line_total_amount',
        ];

        foreach ($revision->items->values() as $index => $item) {
            $expected = $calculated[$index];

            if ($item->position !== $expected['position'] || $item->discount_type->value !== $expected['discount_type']) {
                throw InvoiceNotReadyForIssue::because('identita nebo sleva položky nesouhlasí s přepočtem');
            }
            foreach ($numeric as $field) {
                if (InvoiceDecimal::compare((string) $item->{$field}, (string) $expected[$field]) !== 0) {
                    throw InvoiceNotReadyForIssue::because('částky položek nesouhlasí s přepočtem');
                }
            }
        }
    }

    /** @param Collection<int, mixed> $stored @param list<array<string, mixed>> $calculated */
    private function assertSummaries(Collection $stored, array $calculated): void
    {
        $actual = $stored->map(fn ($summary): array => [
            'tax_type' => $summary->tax_type->value,
            'percentage' => $summary->percentage,
            'percentage_key' => $summary->percentage_key,
            'tax_base' => $summary->tax_base,
            'vat_amount' => $summary->vat_amount,
            'total_amount' => $summary->total_amount,
        ])->sortBy(fn (array $summary): string => $summary['tax_type'].'|'.$summary['percentage_key'])->values()->all();
        usort($calculated, fn (array $left, array $right): int => ($left['tax_type'].'|'.$left['percentage_key']) <=> ($right['tax_type'].'|'.$right['percentage_key']));

        if (count($actual) !== count($calculated)) {
            throw InvoiceNotReadyForIssue::because('VAT summaries neodpovídají položkám');
        }

        foreach ($actual as $index => $summary) {
            $expected = $calculated[$index];

            if ($summary['tax_type'] !== $expected['tax_type'] || $summary['percentage_key'] !== $expected['percentage_key']) {
                throw InvoiceNotReadyForIssue::because('režimy VAT summaries neodpovídají položkám');
            }
            foreach (['percentage', 'tax_base', 'vat_amount', 'total_amount'] as $field) {
                if ($summary[$field] === null || $expected[$field] === null) {
                    if ($summary[$field] !== $expected[$field]) {
                        throw InvoiceNotReadyForIssue::because('VAT summaries neodpovídají přepočtu');
                    }
                } elseif (InvoiceDecimal::compare((string) $summary[$field], (string) $expected[$field]) !== 0) {
                    throw InvoiceNotReadyForIssue::because('VAT summaries neodpovídají přepočtu');
                }
            }
        }
    }

    /** @param array<string, mixed> $calculation */
    private function assertTotals(InvoiceRevision $revision, array $calculation): void
    {
        $discount = $calculation['invoice_discount'];

        if (
            $revision->invoice_discount_type->value !== $discount['type']
            || InvoiceDecimal::compare((string) $revision->invoice_discount_value, $discount['value']) !== 0
            || InvoiceDecimal::compare((string) $revision->invoice_discount_amount, $discount['amount']) !== 0
        ) {
            throw InvoiceNotReadyForIssue::because('celková sleva neodpovídá přepočtu');
        }

        foreach ($calculation['totals'] as $field => $expected) {
            if (InvoiceDecimal::compare((string) $revision->{$field}, $expected) !== 0) {
                throw InvoiceNotReadyForIssue::because('uložené totals neodpovídají serverovému přepočtu');
            }
        }
    }
}
