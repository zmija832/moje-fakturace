<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BusinessSwitchController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

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

Route::middleware(['auth', 'business.context'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/fakturacni-subjekt/{businessUuid}', BusinessSwitchController::class)
        ->whereUuid('businessUuid')
        ->name('business.switch');

    Route::view('/nastaveni', 'settings')->name('settings');
    Route::put('/nastaveni/heslo', [PasswordController::class, 'update'])->name('password.update');

    Route::middleware('business.required')->group(function (): void {
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

        Route::view('/vydane-faktury', 'coming-soon', ['module' => 'Vydané faktury'])
            ->name('invoices.index');
        Route::get('/klienti', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/klienti/novy', [ClientController::class, 'create'])->name('clients.create');
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
