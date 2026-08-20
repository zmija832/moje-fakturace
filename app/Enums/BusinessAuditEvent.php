<?php

namespace App\Enums;

enum BusinessAuditEvent: string
{
    case CompanySettingsCreated = 'company_settings.created';
    case CompanySettingsUpdated = 'company_settings.updated';
    case InvoiceEmailSettingsCreated = 'invoice_email_settings.created';
    case InvoiceEmailSettingsUpdated = 'invoice_email_settings.updated';
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
    case InvoiceCatalogItemCreated = 'invoice_catalog_item.created';
    case InvoiceCatalogItemUpdated = 'invoice_catalog_item.updated';
    case InvoiceCatalogItemActivated = 'invoice_catalog_item.activated';
    case InvoiceCatalogItemDeactivated = 'invoice_catalog_item.deactivated';
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
    case InvoiceDraftArchived = 'invoice.draft_archived';
    case InvoiceArchived = 'invoice.archived';
    case InvoiceRestored = 'invoice.restored';
    case InvoiceIssuedRevisionCreated = 'invoice.issued_revision_created';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceIssueConflict = 'invoice.issue_conflict';
    case InvoicePdfGenerated = 'invoice.pdf_generated';
    case InvoicePdfGenerationFailed = 'invoice.pdf_generation_failed';
    case InvoiceEmailSendRequested = 'invoice.email_send_requested';
    case InvoiceEmailSent = 'invoice.email_sent';
    case InvoiceEmailFailed = 'invoice.email_failed';
    case InvoicePaymentRecorded = 'invoice.payment_recorded';
    case InvoicePaymentReversed = 'invoice.payment_reversed';
    case InvoicePaymentStatusChanged = 'invoice.payment_status_changed';
    case InvoicePaymentConflict = 'invoice.payment_conflict';
    case InvoicePublicLinkCreated = 'invoice.public_link_created';
    case InvoicePublicLinkRevoked = 'invoice.public_link_revoked';
    case InvoicePublicLinkRegenerated = 'invoice.public_link_regenerated';
    case InvoiceCancelled = 'invoice.cancelled';
    case InvoiceDraftDeleted = 'invoice.draft_deleted';
    case InvoiceTestPurged = 'invoice.test_purged';
    case InvoiceDeleted = 'invoice.deleted';
    case RecurringInvoiceCreated = 'recurring_invoice.created';
    case RecurringInvoiceUpdated = 'recurring_invoice.updated';
    case RecurringInvoicePaused = 'recurring_invoice.paused';
    case RecurringInvoiceResumed = 'recurring_invoice.resumed';
    case RecurringInvoiceManualRun = 'recurring_invoice.manual_run';
    case InvoiceAutomationSettingsUpdated = 'invoice_automation_settings.updated';
    case InvoiceReminderPreferenceChanged = 'invoice.reminder_preference_changed';
    case InvoiceReminderSent = 'invoice.reminder_sent';

    public function label(): string
    {
        return match ($this) {
            self::CompanySettingsCreated => 'Vytvořeno nastavení subjektu',
            self::CompanySettingsUpdated => 'Upraveno nastavení subjektu',
            self::InvoiceEmailSettingsCreated => 'Vytvořeno nastavení e-mailů',
            self::InvoiceEmailSettingsUpdated => 'Upraveno nastavení e-mailů',
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
            self::InvoiceCatalogItemCreated => 'Vytvořena položka katalogu',
            self::InvoiceCatalogItemUpdated => 'Upravena položka katalogu',
            self::InvoiceCatalogItemActivated => 'Aktivována položka katalogu',
            self::InvoiceCatalogItemDeactivated => 'Deaktivována položka katalogu',
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
            self::InvoiceDraftArchived => 'Archivován koncept faktury',
            self::InvoiceArchived => 'Archivována faktura',
            self::InvoiceRestored => 'Obnovena faktura',
            self::InvoiceIssuedRevisionCreated => 'Vytvořena admin revize vystavené faktury',
            self::InvoiceIssued => 'Vystavena faktura',
            self::InvoiceIssueConflict => 'Konflikt při vystavení faktury',
            self::InvoicePdfGenerated => 'Vygenerováno PDF faktury',
            self::InvoicePdfGenerationFailed => 'Generování PDF faktury selhalo',
            self::InvoiceEmailSendRequested => 'Vyžádáno odeslání faktury',
            self::InvoiceEmailSent => 'Faktura odeslána e-mailem',
            self::InvoiceEmailFailed => 'Odeslání faktury selhalo',
            self::InvoicePaymentRecorded => 'Zaevidována platba faktury',
            self::InvoicePaymentReversed => 'Vytvořeno storno platby faktury',
            self::InvoicePaymentStatusChanged => 'Změněn odvozený stav úhrady',
            self::InvoicePaymentConflict => 'Konflikt platební operace',
            self::InvoicePublicLinkCreated => 'Vytvořena Webfaktura',
            self::InvoicePublicLinkRevoked => 'Zrušena Webfaktura',
            self::InvoicePublicLinkRegenerated => 'Obnoven odkaz Webfaktury',
            self::InvoiceCancelled => 'Stornována faktura',
            self::InvoiceDraftDeleted => 'Trvale odstraněn koncept faktury',
            self::InvoiceTestPurged => 'Historicky odstraněna testovací faktura',
            self::InvoiceDeleted => 'Trvale odstraněna faktura',
            self::RecurringInvoiceCreated => 'Vytvořena opakovaná faktura',
            self::RecurringInvoiceUpdated => 'Upravena opakovaná faktura',
            self::RecurringInvoicePaused => 'Pozastavena opakovaná faktura',
            self::RecurringInvoiceResumed => 'Obnovena opakovaná faktura',
            self::RecurringInvoiceManualRun => 'Ručně spuštěna opakovaná faktura',
            self::InvoiceAutomationSettingsUpdated => 'Změněno nastavení automatizace',
            self::InvoiceReminderPreferenceChanged => 'Změněno nastavení upomínek faktury',
            self::InvoiceReminderSent => 'Odeslána upomínka',
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
