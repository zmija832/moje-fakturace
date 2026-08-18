<?php

use App\Http\Controllers\AresClientLookupController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BusinessAuditController;
use App\Http\Controllers\BusinessSwitchController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentSequenceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceDeliveryController;
use App\Http\Controllers\InvoiceEmailSettingController;
use App\Http\Controllers\InvoiceLifecycleController;
use App\Http\Controllers\InvoicePaymentController;
use App\Http\Controllers\InvoicePublicLinkController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\VatRateController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['throttle:60,1', 'public.invoice'])->group(function (): void {
    Route::get('/f/{token}', [PublicInvoiceController::class, 'show'])
        ->where('token', '[A-Za-z0-9_-]{43}')
        ->name('public-invoices.show');
    Route::get('/f/{token}/pdf', [PublicInvoiceController::class, 'pdf'])
        ->where('token', '[A-Za-z0-9_-]{43}')
        ->middleware('throttle:30,1')
        ->name('public-invoices.pdf');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/prihlaseni', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/prihlaseni', [AuthenticatedSessionController::class, 'store']);

    Route::get('/zapomenute-heslo', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/zapomenute-heslo', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('/obnova-hesla/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/obnova-hesla', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware(['business.request-id', 'auth', 'business.context'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/fakturacni-subjekt/{businessUuid}', BusinessSwitchController::class)
        ->whereUuid('businessUuid')
        ->name('business.switch');

    Route::view('/nastaveni', 'settings')->name('settings');
    Route::put('/nastaveni/heslo', [PasswordController::class, 'update'])->name('password.update');

    Route::middleware('business.required')->group(function (): void {
        Route::get('/nastaveni/emaily', [InvoiceEmailSettingController::class, 'edit'])
            ->name('invoice-email-settings.edit');
        Route::put('/nastaveni/emaily', [InvoiceEmailSettingController::class, 'update'])
            ->name('invoice-email-settings.update');

        Route::get('/nastaveni/subjekt', [CompanySettingsController::class, 'edit'])
            ->name('company-settings.edit');
        Route::put('/nastaveni/subjekt', [CompanySettingsController::class, 'update'])
            ->name('company-settings.update');

        Route::get('/nastaveni/bankovni-ucty', [BankAccountController::class, 'index'])
            ->name('bank-accounts.index');
        Route::get('/nastaveni/bankovni-ucty/novy', [BankAccountController::class, 'create'])
            ->name('bank-accounts.create');
        Route::post('/nastaveni/bankovni-ucty', [BankAccountController::class, 'store'])
            ->name('bank-accounts.store');
        Route::get('/nastaveni/bankovni-ucty/{uuid}/upravit', [BankAccountController::class, 'edit'])
            ->whereUuid('uuid')
            ->name('bank-accounts.edit');
        Route::put('/nastaveni/bankovni-ucty/{uuid}', [BankAccountController::class, 'update'])
            ->whereUuid('uuid')
            ->name('bank-accounts.update');
        Route::patch('/nastaveni/bankovni-ucty/{uuid}/nastavit-vychozi', [BankAccountController::class, 'setDefault'])
            ->whereUuid('uuid')
            ->name('bank-accounts.set-default');
        Route::patch('/nastaveni/bankovni-ucty/{uuid}/deaktivovat', [BankAccountController::class, 'deactivate'])
            ->whereUuid('uuid')
            ->name('bank-accounts.deactivate');
        Route::patch('/nastaveni/bankovni-ucty/{uuid}/aktivovat', [BankAccountController::class, 'activate'])
            ->whereUuid('uuid')
            ->name('bank-accounts.activate');
        Route::patch('/nastaveni/bankovni-ucty/{uuid}/archivovat', [BankAccountController::class, 'archive'])
            ->whereUuid('uuid')
            ->name('bank-accounts.archive');

        Route::get('/nastaveni/ciselne-rady', [DocumentSequenceController::class, 'index'])
            ->name('document-sequences.index');
        Route::get('/nastaveni/ciselne-rady/nova', [DocumentSequenceController::class, 'create'])
            ->name('document-sequences.create');
        Route::post('/nastaveni/ciselne-rady', [DocumentSequenceController::class, 'store'])
            ->name('document-sequences.store');
        Route::get('/nastaveni/ciselne-rady/{uuid}', [DocumentSequenceController::class, 'show'])
            ->whereUuid('uuid')->name('document-sequences.show');
        Route::get('/nastaveni/ciselne-rady/{uuid}/upravit', [DocumentSequenceController::class, 'edit'])
            ->whereUuid('uuid')->name('document-sequences.edit');
        Route::put('/nastaveni/ciselne-rady/{uuid}', [DocumentSequenceController::class, 'update'])
            ->whereUuid('uuid')->name('document-sequences.update');
        Route::patch('/nastaveni/ciselne-rady/{uuid}/nastavit-vychozi', [DocumentSequenceController::class, 'setDefault'])
            ->whereUuid('uuid')->name('document-sequences.set-default');
        Route::patch('/nastaveni/ciselne-rady/{uuid}/deaktivovat', [DocumentSequenceController::class, 'deactivate'])
            ->whereUuid('uuid')->name('document-sequences.deactivate');
        Route::patch('/nastaveni/ciselne-rady/{uuid}/aktivovat', [DocumentSequenceController::class, 'activate'])
            ->whereUuid('uuid')->name('document-sequences.activate');
        Route::patch('/nastaveni/ciselne-rady/{uuid}/archivovat', [DocumentSequenceController::class, 'archive'])
            ->whereUuid('uuid')->name('document-sequences.archive');

        Route::get('/nastaveni/sazby-dph', [VatRateController::class, 'index'])
            ->name('vat-rates.index');
        Route::get('/nastaveni/sazby-dph/nova', [VatRateController::class, 'create'])
            ->name('vat-rates.create');
        Route::post('/nastaveni/sazby-dph', [VatRateController::class, 'store'])
            ->name('vat-rates.store');
        Route::patch('/nastaveni/sazby-dph/vychozi/odebrat', [VatRateController::class, 'removeDefault'])
            ->name('vat-rates.remove-default');
        Route::get('/nastaveni/sazby-dph/{uuid}', [VatRateController::class, 'show'])
            ->whereUuid('uuid')->name('vat-rates.show');
        Route::get('/nastaveni/sazby-dph/{uuid}/upravit', [VatRateController::class, 'edit'])
            ->whereUuid('uuid')->name('vat-rates.edit');
        Route::put('/nastaveni/sazby-dph/{uuid}', [VatRateController::class, 'update'])
            ->whereUuid('uuid')->name('vat-rates.update');
        Route::patch('/nastaveni/sazby-dph/{uuid}/nastavit-vychozi', [VatRateController::class, 'setDefault'])
            ->whereUuid('uuid')->name('vat-rates.set-default');
        Route::patch('/nastaveni/sazby-dph/{uuid}/deaktivovat', [VatRateController::class, 'deactivate'])
            ->whereUuid('uuid')->name('vat-rates.deactivate');
        Route::patch('/nastaveni/sazby-dph/{uuid}/aktivovat', [VatRateController::class, 'activate'])
            ->whereUuid('uuid')->name('vat-rates.activate');
        Route::patch('/nastaveni/sazby-dph/{uuid}/archivovat', [VatRateController::class, 'archive'])
            ->whereUuid('uuid')->name('vat-rates.archive');

        Route::get('/nastaveni/audit', [BusinessAuditController::class, 'index'])
            ->name('business-audit.index');
        Route::get('/nastaveni/audit/{uuid}', [BusinessAuditController::class, 'show'])
            ->whereUuid('uuid')->name('business-audit.show');

        Route::get('/faktury', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/faktury/nova', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/faktury/nahled-vypoctu', [InvoiceController::class, 'preview'])
            ->middleware('throttle:60,1')->name('invoices.preview');
        Route::post('/faktury', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/faktury/{uuid}/tiskovy-nahled', [InvoiceDeliveryController::class, 'print'])
            ->whereUuid('uuid')->name('invoices.print');
        Route::post('/faktury/{uuid}/pdf/vygenerovat', [InvoiceDeliveryController::class, 'generate'])
            ->whereUuid('uuid')->name('invoices.pdf.generate');
        Route::get('/faktury/{uuid}/pdf', [InvoiceDeliveryController::class, 'download'])
            ->whereUuid('uuid')->name('invoices.pdf.download');
        Route::get('/faktury/{uuid}/pdf/{documentUuid}', [InvoiceDeliveryController::class, 'download'])
            ->whereUuid(['uuid', 'documentUuid'])->name('invoices.pdf.download-version');
        Route::get('/faktury/{uuid}/odeslat', [InvoiceDeliveryController::class, 'sendForm'])
            ->whereUuid('uuid')->name('invoices.email.form');
        Route::post('/faktury/{uuid}/odeslat', [InvoiceDeliveryController::class, 'send'])
            ->whereUuid('uuid')->name('invoices.email.send');
        Route::get('/faktury/{uuid}/admin-uprava/varovani', [InvoiceController::class, 'issuedEditWarning'])
            ->whereUuid('uuid')->name('invoices.issued-edit.warning');
        Route::post('/faktury/{uuid}/admin-uprava/potvrdit', [InvoiceController::class, 'confirmIssuedEdit'])
            ->whereUuid('uuid')->name('invoices.issued-edit.confirm');
        Route::get('/faktury/{uuid}/admin-uprava', [InvoiceController::class, 'issuedEdit'])
            ->whereUuid('uuid')->name('invoices.issued-edit');
        Route::put('/faktury/{uuid}/admin-uprava', [InvoiceController::class, 'updateIssued'])
            ->whereUuid('uuid')->name('invoices.issued-update');
        Route::get('/faktury/{uuid}/upravit', [InvoiceController::class, 'edit'])
            ->whereUuid('uuid')->name('invoices.edit');
        Route::put('/faktury/{uuid}', [InvoiceController::class, 'update'])
            ->whereUuid('uuid')->name('invoices.update');
        Route::post('/faktury/{uuid}/vystavit', [InvoiceController::class, 'issue'])
            ->whereUuid('uuid')->name('invoices.issue');
        Route::post('/faktury/{uuid}/duplikovat', [InvoiceController::class, 'duplicate'])
            ->whereUuid('uuid')->name('invoices.duplicate');
        Route::patch('/faktury/{uuid}/archivovat', [InvoiceController::class, 'archive'])
            ->whereUuid('uuid')->name('invoices.archive');
        Route::patch('/faktury/{uuid}/obnovit', [InvoiceController::class, 'restore'])
            ->whereUuid('uuid')->name('invoices.restore');
        Route::post('/faktury/{uuid}/stornovat', [InvoiceLifecycleController::class, 'cancel'])
            ->whereUuid('uuid')->name('invoices.cancel');
        Route::delete('/faktury/{uuid}/koncept', [InvoiceLifecycleController::class, 'deleteDraft'])
            ->whereUuid('uuid')->name('invoices.draft.delete');
        Route::delete('/faktury/{uuid}/testovaci-purge', [InvoiceLifecycleController::class, 'purgeTest'])
            ->whereUuid('uuid')->name('invoices.test-purge');
        Route::post('/faktury/{uuid}/webfaktura', [InvoicePublicLinkController::class, 'store'])
            ->whereUuid('uuid')->name('invoices.public-link.store');
        Route::post('/faktury/{uuid}/webfaktura/obnovit', [InvoicePublicLinkController::class, 'regenerate'])
            ->whereUuid('uuid')->name('invoices.public-link.regenerate');
        Route::delete('/faktury/{uuid}/webfaktura', [InvoicePublicLinkController::class, 'revoke'])
            ->whereUuid('uuid')->name('invoices.public-link.revoke');
        Route::post('/faktury/{uuid}/platby', [InvoicePaymentController::class, 'store'])
            ->whereUuid('uuid')->name('invoices.payments.store');
        Route::post('/faktury/{uuid}/platby/{paymentUuid}/storno', [InvoicePaymentController::class, 'reverse'])
            ->whereUuid(['uuid', 'paymentUuid'])->name('invoices.payments.reverse');
        Route::get('/faktury/{uuid}', [InvoiceController::class, 'show'])
            ->whereUuid('uuid')->name('invoices.show');
        Route::get('/klienti', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/klienti/novy', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/klienti/ares/nacist', AresClientLookupController::class)
            ->middleware('throttle:20,1')->name('clients.ares.lookup');
        Route::post('/klienti', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/klienti/{uuid}', [ClientController::class, 'show'])
            ->whereUuid('uuid')->name('clients.show');
        Route::get('/klienti/{uuid}/upravit', [ClientController::class, 'edit'])
            ->whereUuid('uuid')->name('clients.edit');
        Route::put('/klienti/{uuid}', [ClientController::class, 'update'])
            ->whereUuid('uuid')->name('clients.update');
        Route::patch('/klienti/{uuid}/deaktivovat', [ClientController::class, 'deactivate'])
            ->whereUuid('uuid')->name('clients.deactivate');
        Route::patch('/klienti/{uuid}/aktivovat', [ClientController::class, 'activate'])
            ->whereUuid('uuid')->name('clients.activate');
        Route::patch('/klienti/{uuid}/archivovat', [ClientController::class, 'archive'])
            ->whereUuid('uuid')->name('clients.archive');
        Route::view('/pravidelne-fakturace', 'coming-soon', ['module' => 'Pravidelné fakturace'])
            ->name('recurring.index');
        Route::view('/export', 'coming-soon', ['module' => 'Export'])
            ->name('exports.index');
    });

    Route::post('/odhlaseni', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
