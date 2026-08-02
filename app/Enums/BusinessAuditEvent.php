<?php

namespace App\Enums;

enum BusinessAuditEvent: string
{
    case CompanySettingsCreated = 'company_settings.created';
    case CompanySettingsUpdated = 'company_settings.updated';
    case BankAccountCreated = 'bank_account.created';
    case BankAccountUpdated = 'bank_account.updated';
    case BankAccountActivated = 'bank_account.activated';
    case BankAccountDeactivated = 'bank_account.deactivated';
    case BankAccountArchived = 'bank_account.archived';
    case BankAccountDefaultChanged = 'bank_account.default_changed';
    case BankAccountDefaultRemoved = 'bank_account.default_removed';
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientActivated = 'client.activated';
    case ClientDeactivated = 'client.deactivated';
    case ClientArchived = 'client.archived';
    case DocumentSequenceCreated = 'document_sequence.created';
    case DocumentSequenceUpdated = 'document_sequence.updated';
    case DocumentSequenceActivated = 'document_sequence.activated';
    case DocumentSequenceDeactivated = 'document_sequence.deactivated';
    case DocumentSequenceArchived = 'document_sequence.archived';
    case DocumentSequenceDefaultChanged = 'document_sequence.default_changed';
    case DocumentSequenceDefaultRemoved = 'document_sequence.default_removed';
    case DocumentNumberAllocated = 'document_number.allocated';
    case VatRateCreated = 'vat_rate.created';
    case VatRateUpdated = 'vat_rate.updated';
    case VatRateActivated = 'vat_rate.activated';
    case VatRateDeactivated = 'vat_rate.deactivated';
    case VatRateArchived = 'vat_rate.archived';
    case VatRateDefaultChanged = 'vat_rate.default_changed';
    case VatRateDefaultRemoved = 'vat_rate.default_removed';
    case InvoiceDraftCreated = 'invoice.draft_created';
    case InvoiceDraftUpdated = 'invoice.draft_updated';
    case InvoiceDraftRevisionCreated = 'invoice.draft_revision_created';
    case InvoiceDraftUpdateConflict = 'invoice.draft_update_conflict';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceIssueConflict = 'invoice.issue_conflict';

    public function label(): string
    {
        return match ($this) {
            self::CompanySettingsCreated => 'Vytvořeno nastavení subjektu',
            self::CompanySettingsUpdated => 'Upraveno nastavení subjektu',
            self::BankAccountCreated => 'Vytvořen bankovní účet',
            self::BankAccountUpdated => 'Upraven bankovní účet',
            self::BankAccountActivated => 'Aktivován bankovní účet',
            self::BankAccountDeactivated => 'Deaktivován bankovní účet',
            self::BankAccountArchived => 'Archivován bankovní účet',
            self::BankAccountDefaultChanged => 'Změněn výchozí bankovní účet',
            self::BankAccountDefaultRemoved => 'Odstraněn výchozí bankovní účet',
            self::ClientCreated => 'Vytvořen klient',
            self::ClientUpdated => 'Upraven klient',
            self::ClientActivated => 'Aktivován klient',
            self::ClientDeactivated => 'Deaktivován klient',
            self::ClientArchived => 'Archivován klient',
            self::DocumentSequenceCreated => 'Vytvořena číselná řada',
            self::DocumentSequenceUpdated => 'Upravena číselná řada',
            self::DocumentSequenceActivated => 'Aktivována číselná řada',
            self::DocumentSequenceDeactivated => 'Deaktivována číselná řada',
            self::DocumentSequenceArchived => 'Archivována číselná řada',
            self::DocumentSequenceDefaultChanged => 'Změněna výchozí číselná řada',
            self::DocumentSequenceDefaultRemoved => 'Odstraněna výchozí číselná řada',
            self::DocumentNumberAllocated => 'Přiděleno číslo dokladu',
            self::VatRateCreated => 'Vytvořena sazba DPH',
            self::VatRateUpdated => 'Upravena sazba DPH',
            self::VatRateActivated => 'Aktivována sazba DPH',
            self::VatRateDeactivated => 'Deaktivována sazba DPH',
            self::VatRateArchived => 'Archivována sazba DPH',
            self::VatRateDefaultChanged => 'Změněna výchozí sazba DPH',
            self::VatRateDefaultRemoved => 'Odstraněna výchozí sazba DPH',
            self::InvoiceDraftCreated => 'Vytvořen návrh faktury',
            self::InvoiceDraftUpdated => 'Upraven návrh faktury',
            self::InvoiceDraftRevisionCreated => 'Vytvořena revize návrhu faktury',
            self::InvoiceDraftUpdateConflict => 'Konflikt při úpravě návrhu faktury',
            self::InvoiceIssued => 'Vystavena faktura',
            self::InvoiceIssueConflict => 'Konflikt při vystavení faktury',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $event): array => [$event->value, $event->label()],
            self::cases(),
        ), 1, 0);
    }
}
