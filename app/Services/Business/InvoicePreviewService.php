<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\Invoices\InvoiceCalculator;
use App\Enums\DefaultPaymentMethod;
use App\Models\Business\CompanySetting;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class InvoicePreviewService
{
    public function __construct(
        private readonly BusinessConnectionResolver $connectionResolver,
        private readonly InvoiceVatResolver $vatResolver,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function calculate(array $attributes): array
    {
        $this->connectionResolver->resolve();
        $supplier = CompanySetting::query()->where('singleton_key', CompanySetting::SINGLETON_KEY)->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier' => 'Před vytvořením faktury dokončete nastavení fakturačního subjektu.']);
        }
        $currency = (string) $attributes['currency'];

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $attributes['taxable_supply_on']);
        $resolved = $this->vatResolver->resolve($attributes['items'], $date, (bool) $supplier->is_vat_payer);
        $items = $resolved['items'];
        $rates = array_map(static fn ($rate): array => [
            'tax_type' => $rate->tax_type,
            'percentage' => $rate->percentage,
        ], $resolved['rates']);

        $calculation = $this->calculator->calculate(
            $items,
            $rates,
            ['type' => $attributes['invoice_discount_type'] ?? 'none', 'value' => $attributes['invoice_discount_value'] ?? null],
            $currency === 'CZK' && $attributes['payment_method'] === DefaultPaymentMethod::Cash->value ? 0 : 2,
        );

        return [
            'items' => array_map(fn (array $item): array => array_intersect_key($item, array_flip([
                'position', 'unit_price_after_discount', 'line_net_amount', 'vat_amount', 'line_total_amount',
            ])), $calculation['items']),
            'summaries' => $calculation['summaries'],
            'invoice_discount' => $calculation['invoice_discount'],
            'totals' => $calculation['totals'],
        ];
    }
}
