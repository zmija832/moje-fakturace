<?php

namespace App\Domain\Invoices;

use App\Enums\InvoiceDiscountType;
use App\Enums\VatTaxType;
use InvalidArgumentException;

final class InvoiceCalculator
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array{tax_type: VatTaxType, percentage: ?string}>  $vatRates
     * @param  array{type?: mixed, value?: mixed}  $invoiceDiscount
     * @return array{items: list<array<string, mixed>>, summaries: list<array<string, string|null>>, invoice_discount: array{type: string, value: string, amount: string}, totals: array<string, string>}
     */
    public function calculate(array $items, array $vatRates, array $invoiceDiscount = [], int $totalScale = 2): array
    {
        $calculatedItems = [];
        $summaries = [];
        $totals = [
            'subtotal_before_discount' => '0.0000',
            'discount_total' => '0.0000',
            'tax_base_total' => '0.0000',
            'vat_total' => '0.0000',
            'total_before_rounding' => '0.0000',
        ];

        foreach ($items as $item) {
            $vatRateUuid = (string) $item['vat_rate_uuid'];
            $rate = $vatRates[$vatRateUuid] ?? throw new InvalidArgumentException('Položka odkazuje na neznámou sazbu DPH.');
            $line = $this->lineBeforeInvoiceDiscount($item, $rate['tax_type'], $rate['percentage']);
            $calculatedItems[] = $line;

            $totals['subtotal_before_discount'] = InvoiceDecimal::add($totals['subtotal_before_discount'], $line['gross_amount']);
        }

        $discount = $this->invoiceDiscount($invoiceDiscount, $calculatedItems);
        $shares = $this->allocateInvoiceDiscount($calculatedItems, $discount['amount']);

        foreach ($calculatedItems as $index => &$line) {
            $line = $this->finishLine($line, $shares[$index]);

            $totals['discount_total'] = InvoiceDecimal::add(
                $totals['discount_total'],
                InvoiceDecimal::add($line['line_discount_amount'], $line['invoice_discount_amount']),
            );
            $totals['tax_base_total'] = InvoiceDecimal::add($totals['tax_base_total'], $line['line_net_amount']);
            $totals['vat_total'] = InvoiceDecimal::add($totals['vat_total'], $line['vat_amount']);
            $totals['total_before_rounding'] = InvoiceDecimal::add($totals['total_before_rounding'], $line['line_total_amount']);

            $rate = $vatRates[$line['vat_rate_uuid']];
            $key = $rate['tax_type']->value.'|'.($rate['percentage'] ?? 'null');
            $summary = $summaries[$key] ?? [
                'tax_type' => $rate['tax_type']->value,
                'percentage' => $rate['percentage'],
                'percentage_key' => $rate['percentage'] ?? 'null',
                'tax_base' => '0.0000',
                'vat_amount' => '0.0000',
                'total_amount' => '0.0000',
            ];
            $summary['tax_base'] = InvoiceDecimal::add($summary['tax_base'], $line['line_net_amount']);
            $summary['vat_amount'] = InvoiceDecimal::add($summary['vat_amount'], $line['vat_amount']);
            $summary['total_amount'] = InvoiceDecimal::add($summary['total_amount'], $line['line_total_amount']);
            $summaries[$key] = $summary;
        }
        unset($line);

        foreach ($totals as $value) {
            InvoiceDecimal::assertFits($value);
        }

        if (! in_array($totalScale, [0, 2], true)) {
            throw new InvalidArgumentException('Konečné zaokrouhlení faktury má neplatnou přesnost.');
        }

        $grandTotal = InvoiceDecimal::round($totals['total_before_rounding'], $totalScale);
        $grandTotal = InvoiceDecimal::normalize($grandTotal, InvoiceDecimal::SCALE);
        $totals['rounding_adjustment'] = InvoiceDecimal::subtract($grandTotal, $totals['total_before_rounding']);
        $totals['grand_total'] = $grandTotal;

        ksort($summaries);

        return [
            'items' => $calculatedItems,
            'summaries' => array_values($summaries),
            'invoice_discount' => $discount,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function lineBeforeInvoiceDiscount(array $item, VatTaxType $taxType, ?string $vatPercentage): array
    {
        $quantity = InvoiceDecimal::quantity($this->scalar($item['quantity'] ?? null));
        $unitPrice = InvoiceDecimal::money($this->scalar($item['unit_price'] ?? null));
        $discountType = InvoiceDiscountType::from((string) ($item['discount_type'] ?? InvoiceDiscountType::None->value));
        $gross = InvoiceDecimal::multiply($quantity, $unitPrice);
        InvoiceDecimal::assertFits($gross);

        $discountValue = match ($discountType) {
            InvoiceDiscountType::None => '0.0000',
            InvoiceDiscountType::Percentage => InvoiceDecimal::percentage($this->scalar($item['discount_value'] ?? null)),
            InvoiceDiscountType::Fixed => InvoiceDecimal::money($this->scalar($item['discount_value'] ?? null)),
        };
        $discountAmount = match ($discountType) {
            InvoiceDiscountType::None => '0.0000',
            InvoiceDiscountType::Percentage => InvoiceDecimal::divide(
                InvoiceDecimal::multiply($gross, $discountValue, 8),
                '100',
            ),
            InvoiceDiscountType::Fixed => $discountValue,
        };

        if (InvoiceDecimal::compare($discountAmount, $gross) > 0) {
            throw new InvalidArgumentException('Pevná sleva nesmí překročit hrubou hodnotu položky.');
        }

        $lineNet = InvoiceDecimal::subtract($gross, $discountAmount);

        return [
            'position' => (int) $item['position'],
            'description' => trim((string) $item['description']),
            'quantity' => $quantity,
            'unit' => isset($item['unit']) && trim((string) $item['unit']) !== '' ? trim((string) $item['unit']) : null,
            'unit_price' => $unitPrice,
            'discount_type' => $discountType->value,
            'discount_value' => $discountValue,
            'gross_amount' => $gross,
            'discount_amount' => $discountAmount,
            'line_discount_amount' => $discountAmount,
            'net_before_invoice_discount' => $lineNet,
            'vat_rate_uuid' => (string) $item['vat_rate_uuid'],
            'vat_tax_type' => $taxType,
            'vat_percentage' => $vatPercentage,
        ];
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function finishLine(array $line, string $invoiceDiscountAmount): array
    {
        $lineNet = InvoiceDecimal::subtract($line['net_before_invoice_discount'], $invoiceDiscountAmount);
        $unitPriceAfterDiscount = InvoiceDecimal::divide($lineNet, $line['quantity']);
        $taxType = $line['vat_tax_type'];
        $vatPercentage = $line['vat_percentage'];
        $vatAmount = match ($taxType) {
            VatTaxType::Standard, VatTaxType::Reduced => InvoiceDecimal::divide(
                InvoiceDecimal::multiply($lineNet, $vatPercentage ?? throw new InvalidArgumentException('Sazba DPH nemá procentní hodnotu.'), 8),
                '100',
            ),
            VatTaxType::Zero, VatTaxType::Exempt, VatTaxType::ReverseCharge, VatTaxType::OutOfScope, VatTaxType::NonPayer => '0.0000',
        };
        $lineTotal = InvoiceDecimal::add($lineNet, $vatAmount);

        foreach ([$invoiceDiscountAmount, $unitPriceAfterDiscount, $lineNet, $vatAmount, $lineTotal] as $value) {
            InvoiceDecimal::assertFits($value);
        }

        return [
            ...$this->without($line, ['net_before_invoice_discount', 'vat_tax_type', 'vat_percentage']),
            'invoice_discount_amount' => $invoiceDiscountAmount,
            'unit_price_after_discount' => $unitPriceAfterDiscount,
            'line_net_amount' => $lineNet,
            'vat_amount' => $vatAmount,
            'line_total_amount' => $lineTotal,
        ];
    }

    /** @param array{type?: mixed, value?: mixed} $input @param list<array<string, mixed>> $items @return array{type: string, value: string, amount: string} */
    private function invoiceDiscount(array $input, array $items): array
    {
        $type = InvoiceDiscountType::from((string) ($input['type'] ?? InvoiceDiscountType::None->value));
        $base = '0.0000';

        foreach ($items as $item) {
            $base = InvoiceDecimal::add($base, $item['net_before_invoice_discount']);
        }

        $value = match ($type) {
            InvoiceDiscountType::None => '0.0000',
            InvoiceDiscountType::Percentage => InvoiceDecimal::percentage($this->scalar($input['value'] ?? null)),
            InvoiceDiscountType::Fixed => InvoiceDecimal::money($this->scalar($input['value'] ?? null)),
        };
        $amount = match ($type) {
            InvoiceDiscountType::None => '0.0000',
            InvoiceDiscountType::Percentage => InvoiceDecimal::divide(InvoiceDecimal::multiply($base, $value, 8), '100'),
            InvoiceDiscountType::Fixed => $value,
        };

        if ($type === InvoiceDiscountType::None && isset($input['value']) && InvoiceDecimal::compare($this->scalar($input['value']), '0') !== 0) {
            throw new InvalidArgumentException('Faktura bez celkové slevy nesmí obsahovat hodnotu slevy.');
        }

        if (InvoiceDecimal::compare($amount, $base) > 0) {
            throw new InvalidArgumentException('Celková sleva nesmí překročit základ po položkových slevách.');
        }

        InvoiceDecimal::assertFits($amount);

        return ['type' => $type->value, 'value' => $value, 'amount' => $amount];
    }

    /** @param list<array<string, mixed>> $items @return list<string> */
    private function allocateInvoiceDiscount(array $items, string $discount): array
    {
        $remainingBase = '0.0000';

        foreach ($items as $item) {
            $remainingBase = InvoiceDecimal::add($remainingBase, $item['net_before_invoice_discount']);
        }

        $remainingDiscount = $discount;
        $shares = [];

        foreach ($items as $index => $item) {
            $lineBase = $item['net_before_invoice_discount'];

            if ($index === array_key_last($items)) {
                $share = $remainingDiscount;
            } elseif (InvoiceDecimal::compare($remainingDiscount, '0') === 0 || InvoiceDecimal::compare($remainingBase, '0') === 0) {
                $share = '0.0000';
            } else {
                $share = InvoiceDecimal::divide(InvoiceDecimal::multiply($remainingDiscount, $lineBase, 8), $remainingBase);
                $minimum = InvoiceDecimal::subtract($remainingDiscount, InvoiceDecimal::subtract($remainingBase, $lineBase));

                if (InvoiceDecimal::compare($minimum, '0') > 0 && InvoiceDecimal::compare($share, $minimum) < 0) {
                    $share = $minimum;
                }
            }

            if (InvoiceDecimal::compare($share, $lineBase) > 0) {
                $share = $lineBase;
            }
            if (InvoiceDecimal::compare($share, $remainingDiscount) > 0) {
                $share = $remainingDiscount;
            }

            $shares[] = InvoiceDecimal::normalize($share);
            $remainingBase = InvoiceDecimal::subtract($remainingBase, $lineBase);
            $remainingDiscount = InvoiceDecimal::subtract($remainingDiscount, $share);
        }

        if (InvoiceDecimal::compare($remainingDiscount, '0') !== 0) {
            throw new InvalidArgumentException('Celkovou slevu nelze bezpečně rozdělit mezi položky.');
        }

        return $shares;
    }

    /** @param array<string, mixed> $values @param list<string> $keys @return array<string, mixed> */
    private function without(array $values, array $keys): array
    {
        foreach ($keys as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    private function scalar(mixed $value): string|int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Desetinná hodnota musí být předána jako string nebo integer.');
        }

        return $value;
    }
}
