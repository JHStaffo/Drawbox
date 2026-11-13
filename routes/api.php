<?php

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

                Route::middleware('abilities:wellness')->group(function () {
                    Route::get('/summary', [WellnessApiController::class, 'summary'])->name('summary');
                    Route::get('/days', [WellnessApiController::class, 'days'])->name('days');
                    Route::get('/days/{day}', [WellnessApiController::class, 'day'])->name('day');
                    Route::put('/days/{day}', [WellnessApiController::class, 'updateDay'])->name('days.update');
                    Route::get('/leaderboard', [WellnessApiController::class, 'leaderboard'])->name('leaderboard');
                });
            });
        });
    });
});
