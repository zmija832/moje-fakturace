<?php

namespace App\Enums;

enum DocumentType: string
{
    case IssuedInvoice = 'issued_invoice';
    case AdvanceInvoice = 'advance_invoice';
    case CreditNote = 'credit_note';
    case CashReceipt = 'cash_receipt';

    public function label(): string
    {
        return match ($this) {
            self::IssuedInvoice => 'Vydaná faktura',
            self::AdvanceInvoice => 'Zálohová faktura',
            self::CreditNote => 'Dobropis',
            self::CashReceipt => 'Příjmový doklad',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $type): array => [$type->value, $type->label()],
            self::cases(),
        ), 1, 0);
    }
}
