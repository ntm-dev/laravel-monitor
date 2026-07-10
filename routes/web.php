<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Middleware\Authorize;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/', function () {
            $period = request('period', '1h');
            $tab = request('tab', 'overview');

            if (! in_array($period, ['1h', '6h', '24h', '7d'], true)) {
                $period = '1h';
            }

            $tabs = ['overview', 'requests', 'exceptions', 'queries', 'jobs', 'schedule', 'cache', 'outgoing', 'mail', 'users', 'logs'];

            if (! in_array($tab, $tabs, true)) {
                $tab = 'overview';
            }

            return view('monitor::dashboard', ['period' => $period, 'tab' => $tab]);
        })->name('monitor.dashboard');
    });
