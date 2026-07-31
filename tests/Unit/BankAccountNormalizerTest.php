<?php

namespace Tests\Unit;

use App\Domain\BankAccounts\BankAccountNormalizer;
use PHPUnit\Framework\TestCase;

class BankAccountNormalizerTest extends TestCase
{
    public function test_identifiers_are_normalized_without_losing_leading_zeroes(): void
    {
        $normalized = BankAccountNormalizer::normalize([
            'name' => '  Provozní účet  ',
            'domestic_prefix' => '  019 ',
            'domestic_account_number' => ' 000123 456 ',
            'bank_code' => ' 0800 ',
            'iban' => ' cz65 0800 0000 1920 0014 5399 ',
            'bic' => ' giba cz px ',
            'currency' => ' czk ',
        ]);

        $this->assertSame('Provozní účet', $normalized['name']);
        $this->assertSame('019', $normalized['domestic_prefix']);
        $this->assertSame('000123456', $normalized['domestic_account_number']);
        $this->assertSame('0800', $normalized['bank_code']);
        $this->assertSame('CZ6508000000192000145399', $normalized['iban']);
        $this->assertSame('GIBACZPX', $normalized['bic']);
        $this->assertSame('CZK', $normalized['currency']);
    }

    public function test_blank_optional_identifiers_become_null(): void
    {
        $normalized = BankAccountNormalizer::normalize([
            'domestic_prefix' => ' ',
            'domestic_account_number' => '',
            'bank_code' => "\t",
            'iban' => null,
            'bic' => '  ',
        ]);

        $this->assertNull($normalized['domestic_prefix']);
        $this->assertNull($normalized['domestic_account_number']);
        $this->assertNull($normalized['bank_code']);
        $this->assertNull($normalized['iban']);
        $this->assertNull($normalized['bic']);
    }
}
