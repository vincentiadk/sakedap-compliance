<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ComplianceV2Controller;
use App\Http\Controllers\ComplianceV3Controller;
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

// Compliance V2 Routes (aturan 2026+: berbasis tanggal_terbit)
Route::prefix('compliance-v2')->group(function () {
    Route::get('/',                   [ComplianceV2Controller::class, 'index'])->name('compliance_v2.index');
    Route::get('/data',               [ComplianceV2Controller::class, 'data'])->name('compliance_v2.data');
    Route::get('/export',             [ComplianceV2Controller::class, 'export'])->name('compliance_v2.export');
    Route::get('/detail/{id}',        [ComplianceV2Controller::class, 'detail'])->name('compliance_v2.detail');
    Route::get('/detail/{id}/export', [ComplianceV2Controller::class, 'exportDetail'])->name('compliance_v2.detail.export');
});

// Compliance V3 Routes (gabungan: pra-2026 + 2026+)
Route::prefix('compliance-v3')->group(function () {
    Route::get('/',                   [ComplianceV3Controller::class, 'index'])->name('compliance_v3.index');
    Route::get('/data',               [ComplianceV3Controller::class, 'data'])->name('compliance_v3.data');
    Route::get('/export',             [ComplianceV3Controller::class, 'export'])->name('compliance_v3.export');
    Route::get('/detail/{id}',        [ComplianceV3Controller::class, 'detail'])->name('compliance_v3.detail');
    Route::get('/detail/{id}/export', [ComplianceV3Controller::class, 'exportDetail'])->name('compliance_v3.detail.export');
});

Route::get('/test-connection', [ComplianceController::class, 'testConnection']);