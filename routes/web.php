<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Middleware\Authorize;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/', function () {
            $period = request('period', '1h');

            if (! in_array($period, ['1h', '6h', '24h', '7d'], true)) {
                $period = '1h';
            }

            return view('monitor::dashboard', ['period' => $period]);
        })->name('monitor.dashboard');
    });
