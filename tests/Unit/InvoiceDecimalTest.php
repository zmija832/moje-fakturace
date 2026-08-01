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
        return [['100', '100.0000'], ['12,5', '12.5000'], ['0', '0.0000'], ['0001.2500', '1.2500']];
    }

    #[DataProvider('invalidValues')]
    public function test_unsafe_values_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::money($input);
    }

    public static function invalidValues(): array
    {
        return [['-1'], ['1e2'], ['NaN'], ['12.12345'], ['']];
    }

    public function test_quantity_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceDecimal::quantity('0');
    }
}
