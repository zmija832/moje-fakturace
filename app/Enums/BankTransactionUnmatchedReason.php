<?php

namespace App\Enums;

enum BankTransactionUnmatchedReason: string
{
    case MissingVariableSymbol = 'missing_variable_symbol';
    case InvalidVariableSymbol = 'invalid_variable_symbol';
    case NoInvoiceCandidate = 'no_invoice_candidate';
    case MultipleInvoiceCandidates = 'multiple_invoice_candidates';
    case CurrencyMismatch = 'currency_mismatch';
    case BankAccountMismatch = 'bank_account_mismatch';
    case InvoiceAlreadyPaid = 'invoice_already_paid';
    case InvoiceNotEligible = 'invoice_not_eligible';

    public function label(): string
    {
        return match ($this) {
            self::MissingVariableSymbol => 'Chybí variabilní symbol',
            self::InvalidVariableSymbol => 'Neplatný variabilní symbol',
            self::NoInvoiceCandidate => 'Nenalezena odpovídající faktura',
            self::MultipleInvoiceCandidates => 'Nalezeno více možných faktur',
            self::CurrencyMismatch => 'Nesouhlasí měna',
            self::BankAccountMismatch => 'Nesouhlasí bankovní účet',
            self::InvoiceAlreadyPaid => 'Faktura je již uhrazená',
            self::InvoiceNotEligible => 'Faktura není ve stavu vhodném pro párování',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MissingVariableSymbol => 'Platba neobsahuje variabilní symbol, podle kterého by bylo možné najít fakturu.',
            self::InvalidVariableSymbol => 'Variabilní symbol musí obsahovat pouze 1 až 10 číslic.',
            self::NoInvoiceCandidate => 'V aktivním fakturačním subjektu nebyla nalezena faktura se stejným variabilním symbolem.',
            self::MultipleInvoiceCandidates => 'Platba odpovídá více fakturám. Automatické párování bylo z bezpečnostních důvodů zastaveno.',
            self::CurrencyMismatch => 'Faktura se stejným variabilním symbolem používá jinou měnu než bankovní platba.',
            self::BankAccountMismatch => 'Faktura se stejným variabilním symbolem a měnou byla vystavena s jiným bankovním účtem.',
            self::InvoiceAlreadyPaid => 'Odpovídající faktura již nemá podle platebního ledgeru žádný nedoplatek.',
            self::InvoiceNotEligible => 'Odpovídající faktura není vystavená, je archivovaná nebo ji nelze automaticky párovat.',
        };
    }
}
