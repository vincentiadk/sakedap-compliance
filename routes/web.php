<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('home');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// Compliance Routes
Route::prefix('compliance')->group(function () {
    Route::get('/',                    [ComplianceController::class, 'index'])->name('compliance.index');
    Route::get('/data',                [ComplianceController::class, 'data'])->name('compliance.data');
    Route::get('/export',              [ComplianceController::class, 'export'])->name('compliance.export');
    Route::get('/detail/{id}',         [ComplianceController::class, 'detail'])->name('compliance.detail');
    Route::get('/detail/{id}/export',  [ComplianceController::class, 'exportDetail'])->name('compliance.detail.export');
});

Route::get('/test-connection', [ComplianceController::class, 'testConnection']);