<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DrawController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Supervisor\AwardController;
use App\Http\Controllers\Supervisor\DashboardController;
use App\Http\Controllers\Supervisor\InventoryController;
use App\Http\Controllers\Supervisor\ReportController;
use App\Http\Controllers\Supervisor\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showEmail'])->name('login');
    Route::post('/login', [AuthController::class, 'sendCode'])->middleware('throttle:6,1');
    Route::get('/login/code', [AuthController::class, 'showCode'])->name('login.code');
    Route::post('/login/code', [AuthController::class, 'verifyCode'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/credits/{credit}/draw', [DrawController::class, 'store'])->name('draws.store');
    Route::get('/draws/{draw}', [DrawController::class, 'show'])->name('draws.show');
    Route::post('/draws/{draw}/brand', [DrawController::class, 'brand'])->name('draws.brand');

    Route::middleware('role:dispatcher')->group(function () {
        Route::get('/dispatch', [DispatchController::class, 'create'])->name('dispatch.create');
        Route::post('/dispatch', [DispatchController::class, 'store'])->name('dispatch.store');
    });

    Route::middleware('role:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/draws/{draw}/award', [AwardController::class, 'store'])->name('award');
        Route::post('/credits/{credit}/void', [AwardController::class, 'voidCredit'])->name('credits.void');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::get('/reports/prizes.csv', [ReportController::class, 'prizesCsv'])->name('reports.prizes');
        Route::get('/reports/employees.csv', [ReportController::class, 'employeesCsv'])->name('reports.employees');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory');
        Route::post('/inventory/restock', [InventoryController::class, 'restock'])->name('inventory.restock');
        Route::post('/inventory/prizes', [InventoryController::class, 'storePrize'])->name('inventory.prizes.store');
        Route::put('/inventory/prizes/{prize}', [InventoryController::class, 'updatePrize'])->name('inventory.prizes.update');
        Route::post('/inventory/prizes/{prize}/brands', [InventoryController::class, 'storeStock'])->name('inventory.stocks.store');
        Route::put('/inventory/brands/{stock}', [InventoryController::class, 'updateStock'])->name('inventory.stocks.update');
        Route::get('/inventory/log', [InventoryController::class, 'log'])->name('inventory.log');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
