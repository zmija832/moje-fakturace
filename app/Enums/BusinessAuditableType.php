<?php

namespace App\Enums;

enum BusinessAuditableType: string
{
    case CompanySettings = 'company_settings';
    case InvoiceEmailSettings = 'invoice_email_settings';
    case BankAccount = 'bank_account';
    case BankAccountDefault = 'bank_account_default';
    case Client = 'client';
    case DocumentSequence = 'document_sequence';
    case DocumentSequenceDefault = 'document_sequence_default';
    case DocumentNumberAllocation = 'document_number_allocation';
    case VatRate = 'vat_rate';
    case VatRateDefault = 'vat_rate_default';
    case Invoice = 'invoice';
    case InvoiceDocument = 'invoice_document';
    case InvoiceEmailDelivery = 'invoice_email_delivery';
    case InvoicePayment = 'invoice_payment';
    case InvoicePublicLink = 'invoice_public_link';

    public function label(): string
    {
        return match ($this) {
            self::CompanySettings => 'Nastavení subjektu',
            self::InvoiceEmailSettings => 'Nastavení e-mailů',
            self::BankAccount => 'Bankovní účet',
            self::BankAccountDefault => 'Výchozí bankovní účet',
            self::Client => 'Klient',
            self::DocumentSequence => 'Číselná řada',
            self::DocumentSequenceDefault => 'Výchozí číselná řada',
            self::DocumentNumberAllocation => 'Přidělené číslo',
            self::VatRate => 'Sazba DPH',
            self::VatRateDefault => 'Výchozí sazba DPH',
            self::Invoice => 'Faktura',
            self::InvoiceDocument => 'PDF dokument faktury',
            self::InvoiceEmailDelivery => 'Odeslání faktury',
            self::InvoicePayment => 'Platba faktury',
            self::InvoicePublicLink => 'Veřejný odkaz faktury',
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
