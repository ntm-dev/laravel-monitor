<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Controllers\DashboardController;
use LaravelMonitor\Http\Middleware\Authorize;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/', DashboardController::class)->name('monitor.dashboard');
    });
