<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

if ((bool) config('maxguard.provide_auth_routes', true)) {
    if (! Route::has('login')) {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    }

    if (! Route::has('logout')) {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth')
            ->name('logout');
    }
}

Route::redirect('/', '/dashboard');

Route::middleware(config('maxguard.route_middleware', ['auth']))->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');

    Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('/findings/export/xlsx', [FindingController::class, 'exportXlsx'])->name('findings.export.xlsx');
    Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::patch('/findings/{finding}', [FindingController::class, 'update'])->name('findings.update');
    Route::get('/evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');

    Route::get('/scan-center', [ScanController::class, 'index'])->name('scans.index');
    Route::get('/scan-center/live', [ScanController::class, 'live'])->name('scans.live');
    Route::post('/scan-center', [ScanController::class, 'store'])->name('scans.store');
});
