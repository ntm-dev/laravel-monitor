<?php

use Illuminate\Support\Facades\Route;
use LaravelMonitor\Http\Middleware\Authorize;
use LaravelMonitor\Livewire\Card;
use LaravelMonitor\Support\Nav;

Route::domain(config('monitor.domain'))
    ->middleware(array_merge(config('monitor.middleware', ['web']), [Authorize::class]))
    ->prefix(config('monitor.path', 'monitor'))
    ->group(function () {
        Route::get('/', function () {
            $period = request('period', Card::DEFAULT_PERIOD);

            if (! array_key_exists($period, Card::periods())) {
                $period = Card::DEFAULT_PERIOD;
            }

            $tab = request('tab', 'overview');

            if (! in_array($tab, Nav::keys(), true)) {
                $tab = 'overview';
            }

            [$from, $to] = Card::normalizeRange(request('from'), request('to'));

            return view('monitor::dashboard', [
                'period' => $period,
                'tab' => $tab,
                'key' => request('key'),
                'from' => $from,
                'to' => $to,
            ]);
        })->name('monitor.dashboard');
    });
