<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiSettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CopyrightReviewController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\Ga4Controller;
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

Route::redirect('/', '/sites');

Route::middleware(config('maxguard.route_middleware', ['auth']))->group(function (): void {
    Route::redirect('/dashboard', '/sites')->name('dashboard');

    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}/findings', [SiteController::class, 'findings'])->name('sites.findings');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::post('/sites/{site}/ai-assessment', [SiteController::class, 'assess'])->name('sites.ai-assessment');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    Route::get('/sites/{site}/ga4/connect', [Ga4Controller::class, 'connect'])->name('ga4.connect');
    Route::patch('/sites/{site}/ga4', [Ga4Controller::class, 'update'])->name('ga4.update');
    Route::post('/sites/{site}/ga4/sync', [Ga4Controller::class, 'sync'])->name('ga4.sync');

    Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
    Route::get('/findings/export/xlsx', [FindingController::class, 'exportXlsx'])->name('findings.export.xlsx');
    Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::patch('/findings/{finding}', [FindingController::class, 'update'])->name('findings.update');
    Route::patch('/pages/{page}/copyright-review', [CopyrightReviewController::class, 'update'])->name('copyright-reviews.update');

    Route::get('/scan-center', [ScanController::class, 'index'])->name('scans.index');
    Route::get('/scan-center/live', [ScanController::class, 'live'])->name('scans.live');
    Route::post('/scan-center', [ScanController::class, 'store'])->name('scans.store');
    Route::get('/scan-center/{scan}', [ScanController::class, 'show'])->name('scans.show');
    Route::get('/scan-center/{scan}/live', [ScanController::class, 'targetsLive'])->name('scans.targets.live');
    Route::get('/scan-center/{scan}/targets/{target}', [ScanController::class, 'target'])->name('scans.targets.show');

    Route::get('/admin', AdminController::class)->middleware('admin')->name('admin.index');
    Route::get('/admin/ai-settings', [AiSettingController::class, 'index'])->middleware('admin')->name('admin.ai-settings.index');
    Route::patch('/admin/ai-settings', [AiSettingController::class, 'update'])->middleware('admin')->name('admin.ai-settings.update');
});

Route::get('/integrations/ga4/callback', [Ga4Controller::class, 'callback'])
    ->middleware(config('maxguard.route_middleware', ['auth']))
    ->name('ga4.callback');
