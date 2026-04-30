<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankImportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ManualAdjustmentController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::get('/livewire-{hash}/update', fn () => redirect('/admin/production'))
    ->where('hash', '[A-Za-z0-9]+');

Route::redirect('/', '/admin/sales');
Route::redirect('/dashboard', '/admin/sales');
Route::redirect('/sales', '/admin/sales');
Route::redirect('/clients', '/admin/companies');
Route::redirect('/production', '/admin/production');
Route::redirect('/finance', '/admin/finance');
Route::redirect('/risks', '/admin/risks');
Route::redirect('/integrations', '/admin/integrations');
Route::redirect('/manual-adjustments', '/admin/manual-adjustments');
Route::redirect('/imports/bank', '/admin/bank-import');

Route::middleware('guest')->group(function () {
    Route::redirect('/login', '/admin/login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/sales', [SalesController::class, 'index'])->name('sales');
    Route::get('/clients', [ClientController::class, 'index'])->name('clients');
    Route::get('/production', [ProductionController::class, 'index'])->name('production');
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance');
    Route::get('/risks', [RiskController::class, 'index'])->name('risks');
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('/integrations/sync-all', [IntegrationController::class, 'syncAll'])->name('integrations.sync-all');
    Route::post('/integrations/{sourceConnection}/sync', [IntegrationController::class, 'sync'])->name('integrations.sync');
    Route::patch('/integrations/{sourceConnection}/settings', [IntegrationController::class, 'updateSettings'])->name('integrations.settings');
    Route::get('/manual-adjustments', [ManualAdjustmentController::class, 'index'])->name('manual-adjustments');
    Route::post('/manual-adjustments', [ManualAdjustmentController::class, 'store'])->name('manual-adjustments.store');
    Route::get('/imports/bank', [BankImportController::class, 'create'])->name('imports.bank.create');
    Route::post('/imports/bank', [BankImportController::class, 'store'])->name('imports.bank.store');
});
