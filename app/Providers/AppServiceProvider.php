<?php

namespace App\Providers;

use App\Services\AuditChanges;
use App\Services\ProtocolImport\AnthropicKey;
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
        $this->app->bind(\App\Training\Generate\CourseWriter::class, fn () => new \App\Training\Generate\ClaudeCourseWriter(
            app(AnthropicKey::class)->get(),
            config('services.anthropic.model'),
        ));
        $this->app->bind(ProtocolReader::class, fn () => new ClaudeProtocolReader(
            app(AnthropicKey::class)->get(),
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
        // Training profiles set to go automatically to a permission profile: new Team Members and profile changes.
        \App\Models\User::saved(function (\App\Models\User $user) {
            if ($user->wasRecentlyCreated || $user->wasChanged(['permission_profile_id', 'active'])) {
                app(\App\Training\Training::class)->autoAssign($user, auth()->hasUser() ? auth()->user() : null);
            }
        });
        Event::listen('eloquent.created: *', fn ($event, $models) => app(AuditChanges::class)->created($models[0]));
    }
}
