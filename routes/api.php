<?php

use App\Wellness\Http\Controllers\Api\AppleHealthController;
use App\Wellness\Http\Controllers\Api\AuthController;
use App\Wellness\Http\Controllers\Api\MeController;
use App\Wellness\Http\Controllers\Api\WellnessApiController;
use Illuminate\Support\Facades\Route;

// Fast Track Pulse API, version 1, for phone apps and device syncs. Sign in with
// email and a 6-digit code (POST auth/code, then POST auth/token) and send the
// token as "Authorization: Bearer ...". See docs/api.md.
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('/auth/code', [AuthController::class, 'code'])->middleware('throttle:6,1')->name('auth.code');
    Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:10,1')->name('auth.token');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::delete('/auth/token', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [MeController::class, 'show'])->middleware('abilities:wellness')->name('me');

        Route::prefix('wellness')->name('wellness.')->middleware(['wellness.enabled', 'wellness.access'])->group(function () {
            // Before signing up, an app can only show the rules and record the Team Member's agreement.
            Route::middleware('abilities:wellness')->group(function () {
                Route::get('/rules', [WellnessApiController::class, 'rules'])->name('rules');
                Route::post('/join', [WellnessApiController::class, 'join'])->name('join');
            });

            Route::middleware('wellness.joined')->group(function () {
                // A sync key from the Devices page can only send readings.
                Route::post('/readings', [WellnessApiController::class, 'readings'])->middleware('ability:wellness,wellness:import')->name('readings');
                // The Apple Health Shortcut: flat fields and a message to show on the phone. See docs/apple-health-shortcut.md.
                Route::post('/apple-health', [AppleHealthController::class, 'store'])->middleware(['ability:wellness,wellness:import', 'throttle:30,1'])->name('apple-health');

                Route::middleware('abilities:wellness')->group(function () {
                    Route::get('/summary', [WellnessApiController::class, 'summary'])->name('summary');
                    Route::get('/days', [WellnessApiController::class, 'days'])->name('days');
                    Route::get('/days/{day}', [WellnessApiController::class, 'day'])->name('day');
                    Route::put('/days/{day}', [WellnessApiController::class, 'updateDay'])->name('days.update');
                    Route::get('/leaderboard', [WellnessApiController::class, 'leaderboard'])->name('leaderboard');
                    Route::get('/submissions', [WellnessApiController::class, 'submissions'])->name('submissions');
                    Route::post('/submissions', [WellnessApiController::class, 'storeSubmission'])->middleware('throttle:20,1')->name('submissions.store');
                    Route::post('/submissions/{submission}/withdraw', [WellnessApiController::class, 'withdrawSubmission'])->name('submissions.withdraw');
                    Route::get('/awards', [WellnessApiController::class, 'awards'])->name('awards');
                    Route::put('/awards/{award}/brand', [WellnessApiController::class, 'chooseBrand'])->name('awards.brand');
                });
            });
        });
    });
});
