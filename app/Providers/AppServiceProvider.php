<?php

namespace App\Providers;

use App\Domain\Audit\BusinessAuditRequestContext;
use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\BusinessContext\BusinessSwitcher;
use App\Events\InvoicePaymentChanged;
use App\Listeners\AuthenticationAuditSubscriber;
use App\Listeners\HandleInvoicePaidNotification;
use App\Models\Business\AuditLog;
use App\Models\Business\BankAccount;
use App\Models\Business\Client;
use App\Models\Business\CompanySetting;
use App\Models\Business\DocumentSequence;
use App\Models\Business\Invoice;
use App\Models\Business\InvoiceAutomationSetting;
use App\Models\Business\RecurringInvoiceTemplate;
use App\Models\Business\VatRate;
use App\Policies\Business\BankAccountPolicy;
use App\Policies\Business\BusinessAuditPolicy;
use App\Policies\Business\ClientPolicy;
use App\Policies\Business\CompanySettingPolicy;
use App\Policies\Business\DocumentSequencePolicy;
use App\Policies\Business\InvoiceAutomationSettingPolicy;
use App\Policies\Business\InvoicePolicy;
use App\Policies\Business\RecurringInvoiceTemplatePolicy;
use App\Policies\Business\VatRatePolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ActiveBusinessContext::class);
        $this->app->scoped(BusinessAuditRequestContext::class);
        $this->app->scoped(BusinessConnectionResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuthenticationAuditSubscriber::class);
        Event::listen(InvoicePaymentChanged::class, HandleInvoicePaidNotification::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(AuditLog::class, BusinessAuditPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(CompanySetting::class, CompanySettingPolicy::class);
        Gate::policy(DocumentSequence::class, DocumentSequencePolicy::class);
        Gate::policy(VatRate::class, VatRatePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(RecurringInvoiceTemplate::class, RecurringInvoiceTemplatePolicy::class);
        Gate::policy(InvoiceAutomationSetting::class, InvoiceAutomationSettingPolicy::class);

        View::composer('components.layouts.app', function ($view): void {
            $user = auth()->user();

            $view->with([
                'navigationBusinesses' => $user
                    ? app(BusinessSwitcher::class)->allowedBusinesses($user)
                    : collect(),
                'navigationActiveBusiness' => app(ActiveBusinessContext::class)->business(),
            ]);
        });
    }
}
