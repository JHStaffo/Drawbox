<?php

use App\Wellness\Http\Controllers\ActivityController;
use App\Wellness\Http\Controllers\Admin\ParticipantController;
use App\Wellness\Http\Controllers\Admin\ReviewController;
use App\Wellness\Http\Controllers\Admin\RewardController;
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
                Route::get('/activities', [ActivityController::class, 'index'])->name('.activities');
                Route::post('/activities', [ActivityController::class, 'store'])->middleware('throttle:20,1')->name('.activities.store');
                Route::post('/activities/{submission}/withdraw', [ActivityController::class, 'withdraw'])->name('.activities.withdraw');
                Route::get('/activities/{submission}/note', [ActivityController::class, 'note'])->name('.activities.note');
                Route::post('/rewards/{award}/brand', [ActivityController::class, 'brand'])->name('.rewards.brand');
            });
        });

        Route::middleware('permission:wellness_admin')->group(function () {
            Route::get('/admin/participants', [ParticipantController::class, 'index'])->name('.admin.participants');
            Route::get('/admin/rules', [RuleController::class, 'index'])->name('.admin.rules');
            Route::post('/admin/rules/{key}', [RuleController::class, 'store'])->name('.admin.rules.store');
            Route::get('/admin/participants/{participant}', [ParticipantController::class, 'show'])->name('.admin.participant');
            Route::post('/admin/participants/{participant}/adjust', [ParticipantController::class, 'adjust'])->name('.admin.adjust');
            Route::get('/admin/rewards', [RewardController::class, 'index'])->name('.admin.rewards');
            Route::post('/admin/rewards/{award}/sent', [RewardController::class, 'sent'])->name('.admin.rewards.sent');
            Route::post('/admin/rewards/{award}/void', [RewardController::class, 'void'])->name('.admin.rewards.void');
            Route::get('/admin/rewards.csv', [RewardController::class, 'csv'])->name('.admin.rewards.csv');
            Route::get('/admin/reports/week.csv', [RewardController::class, 'weeklyCsv'])->name('.admin.reports.week');
            Route::post('/admin/testers', [ParticipantController::class, 'addTester'])->name('.admin.testers.store');
            Route::delete('/admin/testers/{tester}', [ParticipantController::class, 'removeTester'])->name('.admin.testers.destroy');
        });
        Route::middleware('permission:wellness_review')->group(function () {
            Route::put('/admin/participants/{participant}/exception', [ParticipantController::class, 'exception'])->name('.admin.exception');
            Route::get('/admin/review', [ReviewController::class, 'index'])->name('.admin.review');
            Route::get('/admin/review/{submission}/note', [ReviewController::class, 'note'])->name('.admin.review.note');
            Route::post('/admin/review/{submission}/approve', [ReviewController::class, 'approve'])->name('.admin.review.approve');
            Route::post('/admin/review/{submission}/reject', [ReviewController::class, 'reject'])->name('.admin.review.reject');
        });
    });
});
