<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DrawController as AdminDrawController;
use App\Http\Controllers\Admin\FormAdminController;
use App\Http\Controllers\Admin\LockerController;
use App\Http\Controllers\Admin\OptionListController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RemoteLockController;
use App\Http\Controllers\Admin\ScopeRuleController;
use App\Http\Controllers\Admin\SlackController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DrawController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\FormReportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PickUpController;
use App\Http\Controllers\Protocols\ApprovalController as ProtocolApprovalController;
use App\Http\Controllers\Protocols\BookController as ProtocolBookController;
use App\Http\Controllers\Protocols\BuildController as ProtocolBuildController;
use App\Http\Controllers\Protocols\ImportController as ProtocolImportController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\Supervisor\AwardController;
use App\Http\Controllers\Supervisor\DashboardController;
use App\Http\Controllers\Supervisor\DispenseController;
use App\Http\Controllers\Supervisor\InventoryController;
use App\Http\Controllers\Supervisor\ReportController;
use App\Http\Controllers\Supervisor\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showEmail'])->name('login');
    Route::post('/login', [AuthController::class, 'sendCode'])->middleware('throttle:6,1')->name('login.send');
    Route::get('/login/code', [AuthController::class, 'showCode'])->name('login.code');
    Route::post('/login/code', [AuthController::class, 'verifyCode'])->middleware('throttle:10,1')->name('login.verify');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [HomeController::class, 'index'])->name('home');
    // Draw Box Home shows the same page as Home for now; they'll split later.
    Route::get('/draw-box', [HomeController::class, 'index'])->name('drawbox.home');
    Route::post('/credits/{credit}/draw', [DrawController::class, 'store'])->name('draws.store');
    Route::get('/draws/{draw}', [DrawController::class, 'show'])->name('draws.show');
    Route::post('/draws/{draw}/brand', [DrawController::class, 'brand'])->name('draws.brand');
    Route::post('/draws/{draw}/cash-bonus', [DrawController::class, 'cashBonus'])->name('draws.cash-bonus');
    Route::post('/lockers/{assignment}/picked-up', [PickUpController::class, 'store'])->name('lockers.picked-up');

    // Each group needs one permission from the person's permission profile.
    Route::middleware('permission:credit_calls')->group(function () {
        Route::get('/dispatch', [DispatchController::class, 'create'])->name('dispatch.create');
        Route::post('/dispatch', [DispatchController::class, 'store'])->name('dispatch.store');
        Route::delete('/dispatch/calls/{call}', [DispatchController::class, 'destroy'])->name('dispatch.destroy');
    });
    Route::get('/scope', [ScopeController::class, 'index'])->middleware('permission:scope_check')->name('scope');
    Route::middleware('permission:form_reports')->group(function () {
        Route::get('/forms/reports', [FormReportController::class, 'index'])->name('forms.reports');
        Route::get('/forms/reports.csv', [FormReportController::class, 'csv'])->name('forms.reports.csv');
    });
    Route::middleware('permission:forms')->group(function () {
        Route::get('/forms', [FormController::class, 'index'])->name('forms');
        Route::get('/forms/{form}', [FormController::class, 'show'])->name('forms.show');
        Route::post('/forms/{form}', [FormController::class, 'store'])->middleware('throttle:30,1')->name('forms.store');
    });

    // Protocols: builders write drafts, the Medical Director approves them, everyone with access reads the book.
    Route::prefix('protocols')->name('protocols.')->group(function () {
        Route::middleware('permission:protocols_build')->group(function () {
            Route::get('/manage', [ProtocolBuildController::class, 'index'])->name('manage');
            Route::get('/manage/new', [ProtocolBuildController::class, 'create'])->name('manage.create');
            Route::post('/manage', [ProtocolBuildController::class, 'store'])->name('manage.store');
            Route::post('/manage/preview', [ProtocolBuildController::class, 'preview'])->name('manage.preview');
            Route::post('/manage/recategorize', [ProtocolBuildController::class, 'recategorize'])->name('manage.recategorize');
            Route::get('/manage/{protocol}', [ProtocolBuildController::class, 'edit'])->name('manage.edit');
            Route::put('/manage/{protocol}', [ProtocolBuildController::class, 'update'])->name('manage.update');
            Route::post('/manage/{protocol}/revise', [ProtocolBuildController::class, 'revise'])->name('manage.revise');
            Route::post('/manage/{protocol}/submit', [ProtocolBuildController::class, 'submit'])->name('manage.submit');
            Route::post('/manage/{protocol}/withdraw', [ProtocolBuildController::class, 'withdraw'])->name('manage.withdraw');
            Route::delete('/manage/{protocol}/draft', [ProtocolBuildController::class, 'discard'])->name('manage.discard');
            Route::get('/imports', [ProtocolImportController::class, 'index'])->name('imports');
            Route::post('/imports', [ProtocolImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
            Route::post('/imports/{import}/retry', [ProtocolImportController::class, 'retry'])->name('imports.retry');
            Route::delete('/imports/{import}', [ProtocolImportController::class, 'destroy'])->name('imports.destroy');
        });
        Route::middleware('permission:settings')->group(function () {
            Route::post('/categories', [ProtocolBuildController::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [ProtocolBuildController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [ProtocolBuildController::class, 'destroyCategory'])->name('categories.destroy');
        });
        Route::middleware('permission:protocols_approve')->group(function () {
            Route::get('/approvals', [ProtocolApprovalController::class, 'index'])->name('approvals');
            Route::post('/approvals/batch', [ProtocolApprovalController::class, 'approveMany'])->name('approvals.batch');
            Route::get('/approvals/{version}', [ProtocolApprovalController::class, 'show'])->name('approvals.show');
            Route::post('/approvals/{version}/approve', [ProtocolApprovalController::class, 'approve'])->name('approvals.approve');
            Route::post('/approvals/{version}/return', [ProtocolApprovalController::class, 'sendBack'])->name('approvals.return');
            Route::post('/{protocol}/retire', [ProtocolApprovalController::class, 'retire'])->whereNumber('protocol')->name('retire');
        });
        Route::get('/versions/{version}', [ProtocolBookController::class, 'version'])->name('version');
        Route::middleware('permission:protocols')->group(function () {
            Route::get('/', [ProtocolBookController::class, 'index'])->name('index');
            Route::get('/book', [ProtocolBookController::class, 'book'])->name('book');
            Route::get('/{protocol}', [ProtocolBookController::class, 'show'])->whereNumber('protocol')->name('show');
        });
    });

    // Draw Box pages keep their /supervisor addresses.
    Route::prefix('supervisor')->name('supervisor.')->group(function () {
        Route::middleware('permission:dashboard')->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
            Route::post('/draws/{draw}/award', [AwardController::class, 'store'])->name('award');
            Route::post('/credits/{credit}/void', [AwardController::class, 'voidCredit'])->name('credits.void');
            Route::get('/dispense/{user}', [DispenseController::class, 'create'])->name('dispense');
            Route::post('/dispense/{user}', [DispenseController::class, 'store'])->name('dispense.store');
            Route::post('/lockers/{assignment}/picked-up', [DispenseController::class, 'pickUp'])->name('lockers.picked-up');
            Route::post('/lockers/{assignment}/forfeit', [DispenseController::class, 'forfeit'])->name('lockers.forfeit');
        });

        Route::middleware('permission:reports')->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('reports');
            Route::get('/reports/won', [ReportController::class, 'won'])->name('reports.won');
            Route::get('/reports/won.csv', [ReportController::class, 'wonCsv'])->name('reports.won.csv');
            Route::get('/reports/pto', [ReportController::class, 'pto'])->name('reports.pto');
            Route::get('/reports/pto.csv', [ReportController::class, 'ptoCsv'])->name('reports.pto.csv');
            Route::get('/reports/prizes.csv', [ReportController::class, 'prizesCsv'])->name('reports.prizes');
            Route::get('/reports/employees.csv', [ReportController::class, 'employeesCsv'])->name('reports.employees');
        });

        Route::middleware('permission:inventory')->group(function () {
            Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory');
            Route::post('/inventory/reset', [InventoryController::class, 'resetAvailable'])->name('inventory.reset');
            Route::post('/inventory/prizes', [InventoryController::class, 'storePrize'])->name('inventory.prizes.store');
            Route::put('/inventory/prizes/{prize}', [InventoryController::class, 'updatePrize'])->name('inventory.prizes.update');
            Route::post('/inventory/prizes/{prize}/brands', [InventoryController::class, 'storeStock'])->name('inventory.stocks.store');
            Route::put('/inventory/brands/{stock}', [InventoryController::class, 'updateStock'])->name('inventory.stocks.update');
            Route::put('/inventory/prizes/{prize}/counts', [InventoryController::class, 'updateCounts'])->name('inventory.counts.update');
            Route::post('/inventory/prizes/{prize}/toggle', [InventoryController::class, 'togglePrize'])->name('inventory.prizes.toggle');
            Route::delete('/inventory/prizes/{prize}', [InventoryController::class, 'destroyPrize'])->name('inventory.prizes.destroy');
            Route::post('/inventory/brands/{stock}/toggle', [InventoryController::class, 'toggleStock'])->name('inventory.stocks.toggle');
            Route::delete('/inventory/brands/{stock}', [InventoryController::class, 'destroyStock'])->name('inventory.stocks.destroy');
            Route::get('/inventory/log', [InventoryController::class, 'log'])->name('inventory.log');
        });

        Route::middleware('permission:settings')->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('/settings/anthropic', [SettingsController::class, 'updateAnthropic'])->middleware('throttle:10,1')->name('settings.anthropic');
            Route::delete('/settings/anthropic', [SettingsController::class, 'destroyAnthropic'])->name('settings.anthropic.destroy');
        });
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('permission:team_members')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::post('/users/import', [UserController::class, 'import'])->name('users.import');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });
        Route::get('/lockers', [LockerController::class, 'index'])->middleware('permission:lockers')->name('lockers');
        Route::get('/audit', [AuditLogController::class, 'index'])->middleware('permission:audit_log')->name('audit');
        Route::middleware('permission:settings')->group(function () {
            Route::post('/scope/items', [ScopeRuleController::class, 'storeItem'])->name('scope.items.store');
            Route::put('/scope/items/{item}', [ScopeRuleController::class, 'updateItem'])->name('scope.items.update');
            Route::delete('/scope/items/{item}', [ScopeRuleController::class, 'destroyItem'])->name('scope.items.destroy');
            Route::post('/scope/rules', [ScopeRuleController::class, 'storeRule'])->name('scope.rules.store');
            Route::put('/scope/rules/{rule}', [ScopeRuleController::class, 'updateRule'])->name('scope.rules.update');
            Route::delete('/scope/rules/{rule}', [ScopeRuleController::class, 'destroyRule'])->name('scope.rules.destroy');
            Route::put('/scope/options', [ScopeRuleController::class, 'updateOptions'])->name('scope.options');
        });
        Route::middleware('permission:edit_won')->group(function () {
            Route::get('/draws/{draw}/edit', [AdminDrawController::class, 'edit'])->name('draws.edit');
            Route::put('/draws/{draw}', [AdminDrawController::class, 'update'])->name('draws.update');
            Route::delete('/draws/{draw}', [AdminDrawController::class, 'destroy'])->name('draws.destroy');
        });
        Route::middleware('permission:manage_forms')->group(function () {
            Route::get('/forms', [FormAdminController::class, 'index'])->name('forms');
            Route::post('/forms', [FormAdminController::class, 'store'])->name('forms.store');
            Route::get('/forms/submissions', [FormAdminController::class, 'submissions'])->name('forms.submissions');
            Route::post('/forms/submissions/{submission}/resend', [FormAdminController::class, 'resend'])->name('forms.resend');
            Route::get('/forms/{form}', [FormAdminController::class, 'edit'])->name('forms.edit');
            Route::put('/forms/{form}', [FormAdminController::class, 'update'])->name('forms.update');
            Route::delete('/forms/{form}', [FormAdminController::class, 'destroy'])->name('forms.destroy');
            Route::post('/forms/{form}/test', [FormAdminController::class, 'test'])->name('forms.test');
            Route::post('/forms/{form}/fields', [FormAdminController::class, 'storeField'])->name('forms.fields.store');
            Route::put('/form-fields/{field}', [FormAdminController::class, 'updateField'])->name('forms.fields.update');
            Route::delete('/form-fields/{field}', [FormAdminController::class, 'destroyField'])->name('forms.fields.destroy');
            Route::post('/form-fields/{field}/move', [FormAdminController::class, 'moveField'])->name('forms.fields.move');
            Route::get('/lists', [OptionListController::class, 'index'])->name('lists');
            Route::post('/lists', [OptionListController::class, 'store'])->name('lists.store');
            Route::put('/lists/{list}', [OptionListController::class, 'update'])->name('lists.update');
            Route::delete('/lists/{list}', [OptionListController::class, 'destroy'])->name('lists.destroy');
            Route::post('/lists/{list}/items', [OptionListController::class, 'storeItems'])->name('lists.items.store');
            Route::put('/list-items/{item}', [OptionListController::class, 'updateItem'])->name('lists.items.update');
            Route::post('/list-items/{item}/toggle', [OptionListController::class, 'toggleItem'])->name('lists.items.toggle');
            Route::delete('/list-items/{item}', [OptionListController::class, 'destroyItem'])->name('lists.items.destroy');
            Route::get('/slack', [SlackController::class, 'edit'])->name('slack');
            Route::put('/slack', [SlackController::class, 'update'])->name('slack.update');
            Route::delete('/slack', [SlackController::class, 'destroy'])->name('slack.destroy');
            Route::post('/slack/test', [SlackController::class, 'test'])->name('slack.test');
        });
        Route::middleware('permission:remotelock')->prefix('remotelock')->name('remotelock')->group(function () {
            Route::get('/', [RemoteLockController::class, 'index']);
            Route::put('/', [RemoteLockController::class, 'connect'])->middleware('throttle:10,1')->name('.connect');
            Route::delete('/', [RemoteLockController::class, 'disconnect'])->name('.disconnect');
            Route::put('/options', [RemoteLockController::class, 'options'])->name('.options');
            Route::post('/link', [RemoteLockController::class, 'link'])->name('.link');
            Route::delete('/link/{user}', [RemoteLockController::class, 'unlink'])->name('.unlink');
            Route::post('/create', [RemoteLockController::class, 'create'])->name('.create');
            Route::post('/grant/{user}', [RemoteLockController::class, 'grant'])->name('.grant');
            Route::post('/deactivate', [RemoteLockController::class, 'deactivate'])->name('.deactivate');
            Route::put('/doors', [RemoteLockController::class, 'doors'])->name('.doors');
            Route::post('/sync', [RemoteLockController::class, 'sync'])->name('.sync');
        });
        Route::middleware('permission:profiles')->group(function () {
            Route::get('/profiles', [ProfileController::class, 'index'])->name('profiles');
            Route::post('/profiles', [ProfileController::class, 'store'])->name('profiles.store');
            Route::put('/profiles/default', [ProfileController::class, 'setDefault'])->name('profiles.default');
            Route::put('/profiles/{profile}', [ProfileController::class, 'update'])->name('profiles.update');
            Route::post('/profiles/{profile}/duplicate', [ProfileController::class, 'duplicate'])->name('profiles.duplicate');
            Route::delete('/profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');
        });
    });
});
