<?php

namespace Tests\Unit;

use App\Domain\Invoices\InvoiceVariableSymbol;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceVariableSymbolTest extends TestCase
{
    #[DataProvider('documentNumbers')]
    public function test_it_derives_numeric_variable_symbol(string $documentNumber, string $expected): void
    {
        $this->assertSame($expected, InvoiceVariableSymbol::fromDocumentNumber($documentNumber));
    }

    public static function documentNumbers(): array
    {
        return [
            ['1234567890', '1234567890'],
            ['FA-202600123', '202600123'],
            ['FV/2026/00001', '202600001'],
            ['123456', '123456'],
        ];
    }

    #[DataProvider('invalidDocumentNumbers')]
    public function test_it_fails_closed_when_number_cannot_fit_current_invoice_validation(string $documentNumber): void
    {
        $this->expectException(ValidationException::class);

        InvoiceVariableSymbol::fromDocumentNumber($documentNumber);
    }

    public static function invalidDocumentNumbers(): array
    {
        return [
            ['FA-BEZ-CISLA'],
            ['12345678901'],
        ];
    }
}
