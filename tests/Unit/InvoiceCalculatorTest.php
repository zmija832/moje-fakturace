<?php

namespace Tests\Unit;

use App\Domain\Invoices\InvoiceCalculator;
use App\Enums\VatTaxType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    public function test_line_discounts_totals_and_item_level_vat_are_exact(): void
    {
        $result = $this->calculator()->calculate([
            $this->item(1, '2.5000', '100.0000', 'percentage', '10', 'standard'),
            $this->item(2, '1', '50', 'fixed', '5', 'standard'),
        ], ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']]);

        $this->assertSame('250.0000', $result['items'][0]['gross_amount']);
        $this->assertSame('25.0000', $result['items'][0]['discount_amount']);
        $this->assertSame('90.0000', $result['items'][0]['unit_price_after_discount']);
        $this->assertSame('225.0000', $result['items'][0]['line_net_amount']);
        $this->assertSame('47.2500', $result['items'][0]['vat_amount']);
        $this->assertSame('272.2500', $result['items'][0]['line_total_amount']);
        $this->assertSame('300.0000', $result['totals']['subtotal_before_discount']);
        $this->assertSame('30.0000', $result['totals']['discount_total']);
        $this->assertSame('270.0000', $result['totals']['tax_base_total']);
        $this->assertSame('56.7000', $result['totals']['vat_total']);
        $this->assertSame('326.7000', $result['totals']['grand_total']);
        $this->assertCount(1, $result['summaries']);
    }

    #[DataProvider('zeroVatTypes')]
    public function test_non_taxed_modes_remain_separate_and_have_zero_vat(VatTaxType $type): void
    {
        $key = $type->value;
        $result = $this->calculator()->calculate(
            [$this->item(1, '1', '10', 'none', null, $key)],
            [$key => ['tax_type' => $type, 'percentage' => $type === VatTaxType::Zero ? '0.0000' : null]],
        );

        $this->assertSame('0.0000', $result['items'][0]['vat_amount']);
        $this->assertSame($key, $result['summaries'][0]['tax_type']);
    }

    public static function zeroVatTypes(): array
    {
        return array_map(static fn (VatTaxType $type): array => [$type], [
            VatTaxType::Zero, VatTaxType::Exempt, VatTaxType::ReverseCharge, VatTaxType::OutOfScope,
        ]);
    }

    public function test_reduced_and_standard_rates_create_different_summaries(): void
    {
        $result = $this->calculator()->calculate([
            $this->item(1, '1', '100', 'none', null, 'standard'),
            $this->item(2, '1', '100', 'none', null, 'reduced'),
        ], [
            'standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000'],
            'reduced' => ['tax_type' => VatTaxType::Reduced, 'percentage' => '12.0000'],
        ]);

        $this->assertCount(2, $result['summaries']);
        $this->assertSame('33.0000', $result['totals']['vat_total']);
    }

    public function test_full_discount_and_rounding_adjustment_are_deterministic(): void
    {
        $result = $this->calculator()->calculate([
            $this->item(1, '1', '1.0050', 'percentage', '100', 'standard'),
            $this->item(2, '1', '1.0050', 'none', null, 'standard'),
        ], ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']]);

        $this->assertSame('0.0000', $result['items'][0]['line_total_amount']);
        $this->assertSame('1.2161', $result['totals']['total_before_rounding']);
        $this->assertSame('0.0039', $result['totals']['rounding_adjustment']);
        $this->assertSame('1.2200', $result['totals']['grand_total']);
        $this->assertSame($result, $this->calculator()->calculate([
            $this->item(1, '1', '1.0050', 'percentage', '100', 'standard'),
            $this->item(2, '1', '1.0050', 'none', null, 'standard'),
        ], ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']]));
    }

    public function test_fixed_discount_cannot_exceed_gross_line_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate(
            [$this->item(1, '0.5', '10', 'fixed', '5.0001', 'standard')],
            ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']],
        );
    }

    public function test_invoice_discount_is_allocated_before_vat_across_different_rates(): void
    {
        $result = $this->calculator()->calculate([
            $this->item(1, '1', '100', 'percentage', '10', 'standard'),
            $this->item(2, '1', '100', 'fixed', '10', 'reduced'),
        ], [
            'standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000'],
            'reduced' => ['tax_type' => VatTaxType::Reduced, 'percentage' => '12.0000'],
        ], ['type' => 'fixed', 'value' => '30']);

        $this->assertSame(['type' => 'fixed', 'value' => '30.0000', 'amount' => '30.0000'], $result['invoice_discount']);
        $this->assertSame('10.0000', $result['items'][0]['line_discount_amount']);
        $this->assertSame('15.0000', $result['items'][0]['invoice_discount_amount']);
        $this->assertSame('75.0000', $result['items'][0]['line_net_amount']);
        $this->assertSame('15.7500', $result['items'][0]['vat_amount']);
        $this->assertSame('15.0000', $result['items'][1]['invoice_discount_amount']);
        $this->assertSame('9.0000', $result['items'][1]['vat_amount']);
        $this->assertSame('50.0000', $result['totals']['discount_total']);
        $this->assertSame('150.0000', $result['totals']['tax_base_total']);
        $this->assertSame('24.7500', $result['totals']['vat_total']);
        $this->assertSame('174.7500', $result['totals']['grand_total']);
    }

    public function test_invoice_discount_allocation_preserves_exact_fixed_amount_and_is_deterministic(): void
    {
        $items = [
            $this->item(1, '1', '1', 'none', null, 'standard'),
            $this->item(2, '1', '1', 'none', null, 'standard'),
            $this->item(3, '1', '1', 'none', null, 'standard'),
        ];
        $rates = ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']];
        $result = $this->calculator()->calculate($items, $rates, ['type' => 'fixed', 'value' => '1']);

        $this->assertSame(['0.3333', '0.3334', '0.3333'], array_column($result['items'], 'invoice_discount_amount'));
        $this->assertSame('1.0000', $result['invoice_discount']['amount']);
        $this->assertSame('1.0000', $result['totals']['discount_total']);
        $this->assertSame($result, $this->calculator()->calculate($items, $rates, ['type' => 'fixed', 'value' => '1']));
    }

    public function test_invoice_discount_cannot_exceed_base_after_line_discounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate(
            [$this->item(1, '1', '10', 'percentage', '50', 'standard')],
            ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']],
            ['type' => 'fixed', 'value' => '5.0001'],
        );
    }

    public function test_cash_czk_rounding_changes_only_payable_total_not_vat_base(): void
    {
        $result = $this->calculator()->calculate(
            [$this->item(1, '1', '10.25', 'none', null, 'standard')],
            ['standard' => ['tax_type' => VatTaxType::Standard, 'percentage' => '21.0000']],
            [],
            0,
        );

        $this->assertSame('10.2500', $result['totals']['tax_base_total']);
        $this->assertSame('2.1525', $result['totals']['vat_total']);
        $this->assertSame('12.4025', $result['totals']['total_before_rounding']);
        $this->assertSame('-0.4025', $result['totals']['rounding_adjustment']);
        $this->assertSame('12.0000', $result['totals']['grand_total']);
    }

    private function calculator(): InvoiceCalculator
    {
        return new InvoiceCalculator;
    }

    /** @return array<string, mixed> */
    private function item(int $position, string $quantity, string $price, string $discountType, ?string $discountValue, string $rate): array
    {
        return [
            'position' => $position, 'description' => "Položka {$position}", 'quantity' => $quantity,
            'unit' => 'ks', 'unit_price' => $price, 'discount_type' => $discountType,
            'discount_value' => $discountValue, 'vat_rate_uuid' => $rate,
        ];
    }
}
