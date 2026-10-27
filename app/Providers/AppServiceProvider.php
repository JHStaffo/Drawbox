<?php

namespace App\Providers;

use App\Services\AuditChanges;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditChanges::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Feed every model save to the audit log's change list.
        Event::listen('eloquent.updated: *', fn ($event, $models) => app(AuditChanges::class)->updated($models[0]));
        Event::listen('eloquent.created: *', fn ($event, $models) => app(AuditChanges::class)->created($models[0]));
    }
}
