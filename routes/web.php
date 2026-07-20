<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Controllers\Auth\InvitationController;
use LaravelMonitor\Http\Controllers\Auth\LoginController;
use LaravelMonitor\Http\Controllers\Auth\SetupController;
use LaravelMonitor\Http\Controllers\DashboardController;
use LaravelMonitor\Http\Controllers\JobAttemptController;
use LaravelMonitor\Http\Controllers\RequestDetailController;
use LaravelMonitor\Http\Controllers\SettingsController;
use LaravelMonitor\Http\Middleware\Authorize;
use LaravelMonitor\Http\Middleware\EnsureMonitorAuthenticated;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/setup', [SetupController::class, 'show'])->name('monitor.setup');
        Route::post('/setup', [SetupController::class, 'store'])->name('monitor.setup.store');
        Route::get('/login', [LoginController::class, 'show'])->name('monitor.login');
        Route::post('/login', [LoginController::class, 'store'])->name('monitor.login.store');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('monitor.logout');
        Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('monitor.invitations.show');
        Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('monitor.invitations.store');

        Route::middleware(EnsureMonitorAuthenticated::class)->group(function () {
            Route::get('/requests/{requestId}', RequestDetailController::class)->name('monitor.requests.show');
            Route::get('/jobs/attempts/{attemptId}', JobAttemptController::class)->name('monitor.jobs.attempts.show');
            Route::post('/settings/system', [SettingsController::class, 'system'])->name('monitor.settings.system');
            Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('monitor.settings.reset');
            Route::get('/{tab?}', DashboardController::class)->name('monitor.dashboard');
        });
    });
