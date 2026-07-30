<?php

namespace App\Providers;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Domain\BusinessContext\BusinessConnectionResolver;
use App\Domain\BusinessContext\BusinessSwitcher;
use App\Listeners\AuthenticationAuditSubscriber;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(BusinessConnectionResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuthenticationAuditSubscriber::class);

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
