<?php

namespace Tests\Unit;

use App\Domain\Vat\VatPercentage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VatPercentageTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_it_normalizes_without_float(string|int $input, string $expected): void
    {
        $this->assertSame($expected, VatPercentage::from($input)->value);
    }

    public static function validValues(): array
    {
        return [['21', '21.0000'], [' 12,5 ', '12.5000'], ['0', '0.0000'], [100, '100.0000'], ['01.2500', '1.2500']];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_unsafe_values(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatPercentage::from($input);
    }

    public static function invalidValues(): array
    {
        return [['-1'], ['100.0001'], ['1e2'], ['NaN'], ['INF'], ['12.12345'], [''], ['  '], ['1,2,3']];
    }
}
