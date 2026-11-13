<?php

namespace App\Providers;

use App\Services\AuditChanges;
use App\Services\ProtocolImport\ClaudeProtocolReader;
use App\Services\ProtocolImport\ProtocolReader;
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
        $this->app->bind(ProtocolReader::class, fn () => new ClaudeProtocolReader(
            config('services.anthropic.key'),
            config('services.anthropic.model'),
        ));
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
