<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BusinessSwitchController;
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

        Route::view('/vydane-faktury', 'coming-soon', ['module' => 'Vydané faktury'])
            ->name('invoices.index');
        Route::view('/klienti', 'coming-soon', ['module' => 'Klienti'])
            ->name('clients.index');
        Route::view('/pravidelne-fakturace', 'coming-soon', ['module' => 'Pravidelné fakturace'])
            ->name('recurring.index');
        Route::view('/export', 'coming-soon', ['module' => 'Export'])
            ->name('exports.index');
    });

    Route::post('/odhlaseni', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
