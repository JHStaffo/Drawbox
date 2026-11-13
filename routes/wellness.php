<?php

use App\Wellness\Http\Controllers\Admin\ParticipantController;
use App\Wellness\Http\Controllers\Admin\RuleController;
use App\Wellness\Http\Controllers\Admin\SettingsController;
use App\Wellness\Http\Controllers\ConnectController;
use App\Wellness\Http\Controllers\WellnessController;
use Illuminate\Support\Facades\Route;

// Wellness module pages. Everything here sits under /wellness and the Wellness
// menu, and disappears (404) while the module is turned off, except its settings.
Route::middleware(['web', 'auth'])->prefix('wellness')->name('wellness')->group(function () {
    Route::middleware('permission:wellness_settings')->group(function () {
        Route::get('/admin/settings', [SettingsController::class, 'edit'])->name('.admin.settings');
        Route::put('/admin/settings', [SettingsController::class, 'update'])->name('.admin.settings.update');
    });

    Route::middleware('wellness.enabled')->group(function () {
        // Profile permission or added individually as a tester (Wellness > Participants).
        Route::middleware('wellness.access')->group(function () {
            // Open before signing up: the "I agree" page and the rules it points to.
            Route::get('/', [WellnessController::class, 'index'])->name('');
            Route::post('/join', [WellnessController::class, 'join'])->name('.join');
            Route::get('/rules', [WellnessController::class, 'rules'])->name('.rules');

            // Everything else only after they've agreed.
            Route::middleware('wellness.joined')->group(function () {
                Route::post('/withdraw', [WellnessController::class, 'withdraw'])->name('.withdraw');
                Route::get('/log/{day?}', [WellnessController::class, 'log'])->name('.log');
                Route::put('/log/{day}', [WellnessController::class, 'save'])->name('.log.save');
                Route::get('/leaderboard', [WellnessController::class, 'leaderboard'])->name('.leaderboard');
                Route::get('/connect', [ConnectController::class, 'index'])->name('.connect');
                Route::post('/connect/keys', [ConnectController::class, 'store'])->name('.connect.store');
                Route::delete('/connect/keys/{token}', [ConnectController::class, 'destroy'])->name('.connect.destroy');
            });
        });

        Route::middleware('permission:wellness_admin')->group(function () {
            Route::get('/admin/participants', [ParticipantController::class, 'index'])->name('.admin.participants');
            Route::get('/admin/rules', [RuleController::class, 'index'])->name('.admin.rules');
            Route::post('/admin/rules/{key}', [RuleController::class, 'store'])->name('.admin.rules.store');
            Route::post('/admin/testers', [ParticipantController::class, 'addTester'])->name('.admin.testers.store');
            Route::delete('/admin/testers/{tester}', [ParticipantController::class, 'removeTester'])->name('.admin.testers.destroy');
        });
        Route::put('/admin/participants/{participant}/exception', [ParticipantController::class, 'exception'])
            ->middleware('permission:wellness_review')->name('.admin.exception');
    });
});
