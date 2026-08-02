<?php

namespace Tests\Unit;

use App\Domain\Invoices\InvoiceDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceDecimalTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_exact_values_are_normalized(string|int $input, string $expected): void
    {
        $this->assertSame($expected, InvoiceDecimal::money($input));
    }

    public static function validValues(): array
    {
        return [
            ['100', '100.0000'], ['12,5', '12.5000'], ['0', '0.0000'],
            ['0001.2500', '1.2500'], ['999999999999999.9999', '999999999999999.9999'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_unsafe_values_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::money($input);
    }

    public static function invalidValues(): array
    {
        return [
            ['-1'], ['1e2'], ['NaN'], ['INF'], ['12.12345'], [''],
            ['1000000000000000.0000'],
        ];
    }

    public function test_quantity_must_be_positive_and_supports_four_places(): void
    {
        $this->assertSame('0.0001', InvoiceDecimal::quantity('0,0001'));
        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::quantity('0');
    }

    public function test_signed_arithmetic_is_exact(): void
    {
        $this->assertSame('1000000000000000.0000', InvoiceDecimal::add('999999999999999.9999', '0.0001'));
        $this->assertSame('-2.2500', InvoiceDecimal::subtract('1.25', '3.5'));
        $this->assertSame('3.0863', InvoiceDecimal::multiply('2.5000', '1.2345'));
        $this->assertSame('0.3333', InvoiceDecimal::divide('1', '3'));
        $this->assertSame('-2.2500', InvoiceDecimal::normalize('-2.25'));
        $this->assertSame('2.2500', InvoiceDecimal::absolute('-2.25'));
    }

    public function test_comparison_does_not_depend_on_lexical_or_float_rules(): void
    {
        $this->assertSame(1, InvoiceDecimal::compare('10.0000', '9.9999'));
        $this->assertSame(0, InvoiceDecimal::compare('001.20', '1.2000'));
        $this->assertSame(-1, InvoiceDecimal::compare('-10', '-9'));
    }

    public function test_rounding_is_half_up_for_positive_and_negative_values(): void
    {
        $this->assertSame('1.01', InvoiceDecimal::round('1.0050', 2));
        $this->assertSame('1.00', InvoiceDecimal::round('1.0049', 2));
        $this->assertSame('-1.01', InvoiceDecimal::round('-1.0050', 2));
        $this->assertSame('12,50', InvoiceDecimal::format('12.5'));
    }

    public function test_division_by_zero_and_database_overflow_are_rejected(): void
    {
        try {
            InvoiceDecimal::divide('1', '0');
            $this->fail('Dělení nulou mělo selhat.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::database('1000000000000000.0000');
    }

    public function test_float_is_not_an_accepted_input_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::money(1.25);
    }
}
