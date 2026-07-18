<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Controllers\CommandRunController;
use LaravelMonitor\Http\Controllers\DashboardController;
use LaravelMonitor\Http\Controllers\JobAttemptController;
use LaravelMonitor\Http\Controllers\RequestDetailController;
use LaravelMonitor\Http\Controllers\SettingsController;
use LaravelMonitor\Http\Middleware\Authorize;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/requests/{requestId}', RequestDetailController::class)->name('monitor.requests.show');
        Route::get('/jobs/attempts/{attemptId}', JobAttemptController::class)->name('monitor.jobs.attempts.show');
        Route::get('/commands/runs/{runId}', CommandRunController::class)->name('monitor.commands.runs.show');
        Route::post('/settings/system', [SettingsController::class, 'system'])->name('monitor.settings.system');
        Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('monitor.settings.reset');
        Route::get('/{tab?}', DashboardController::class)->name('monitor.dashboard');
    });
