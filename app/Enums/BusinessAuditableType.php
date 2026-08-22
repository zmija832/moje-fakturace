<?php

namespace App\Enums;

enum BusinessAuditableType: string
{
    case CompanySettings = 'company_settings';
    case InvoiceEmailSettings = 'invoice_email_settings';
    case BankAccount = 'bank_account';
    case BankAccountDefault = 'bank_account_default';
    case FioBankAccountSetting = 'fio_bank_account_setting';
    case BankTransaction = 'bank_transaction';
    case Client = 'client';
    case InvoiceCatalogItem = 'invoice_catalog_item';
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
    case RecurringInvoice = 'recurring_invoice';
    case InvoiceAutomationSettings = 'invoice_automation_settings';

    public function label(): string
    {
        return match ($this) {
            self::CompanySettings => 'Nastavení subjektu',
            self::InvoiceEmailSettings => 'Nastavení e-mailů',
            self::BankAccount => 'Bankovní účet',
            self::BankAccountDefault => 'Výchozí bankovní účet',
            self::FioBankAccountSetting => 'Fio integrace bankovního účtu',
            self::BankTransaction => 'Bankovní platba',
            self::Client => 'Klient',
            self::InvoiceCatalogItem => 'Položka katalogu',
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
            self::RecurringInvoice => 'Opakovaná faktura',
            self::InvoiceAutomationSettings => 'Nastavení automatizace',
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
