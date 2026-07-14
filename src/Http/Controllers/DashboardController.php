<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use LaravelMonitor\Http\Headings\ExceptionHeading;
use LaravelMonitor\Http\Headings\Heading;
use LaravelMonitor\Http\Headings\JobHeading;
use LaravelMonitor\Http\Headings\RequestHeading;
use LaravelMonitor\Livewire\Card;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\Settings;

/**
 * Renders the dashboard shell: resolves the active tab and time range, then
 * hands presentation-ready data to the (logic-free) Blade views. Per-tab
 * concerns live elsewhere: detail headings in Http\Headings, navigation in
 * Support\Nav, editable settings in Support\Settings.
 */
class DashboardController
{
    public function __invoke(Request $request): View
    {
        // Apply the viewer's language before any label/heading is resolved.
        app()->setLocale(Preferences::locale());

        $period = $request->query('period', Card::DEFAULT_PERIOD);

        if (! array_key_exists($period, Card::periods())) {
            $period = Card::DEFAULT_PERIOD;
        }

        $tab = $request->query('tab', 'overview');

        if (! in_array($tab, Nav::keys(), true)) {
            $tab = 'overview';
        }

        [$from, $to] = Card::normalizeRange($request->query('from'), $request->query('to'));

        $key = $request->query('key');
        $tabs = Nav::tabs();

        [$groups, $footerTabs] = Nav::grouped();

        $detail = $this->heading($tab, $key);

        return view('monitor::dashboard', [
            'tab' => $tab,
            'period' => $period,
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'hasCustomRange' => filled($from) && filled($to),
            // Query-string state carried through every dashboard link.
            'range' => array_filter(['period' => $period, 'from' => $from, 'to' => $to]),
            // Range passed to every Livewire card.
            'rangeProps' => ['period' => $period, 'from' => $from, 'to' => $to],
            'tabs' => $tabs,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'detail' => $detail,
            'title' => $tabs[$tab]['label'],
            'pageTitle' => $detail?->pageTitle ?? $tabs[$tab]['label'],
            'periods' => array_keys(Card::periods()),
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => Format::timezone(),
            'rangeMax' => now()->format(Format::RANGE),
            'system' => $tab === 'settings' ? Settings::current() : null,
            'storageDrivers' => $tab === 'settings' ? Settings::storageDrivers() : null,
            'prefs' => $tab === 'settings' ? Preferences::all() : null,
            'localeOptions' => $tab === 'settings' ? Preferences::localeOptions() : null,
            'timezoneOptions' => $tab === 'settings' ? Preferences::timezoneOptions() : null,
        ]);
    }

    /**
     * Resolve the detail-page heading for tabs that have one.
     */
    protected function heading(string $tab, ?string $key): ?Heading
    {
        if (! filled($key)) {
            return null;
        }

        return match ($tab) {
            'requests' => (new RequestHeading)($key),
            'jobs' => (new JobHeading)($key),
            'exceptions' => app(ExceptionHeading::class)($key),
            default => null,
        };
    }
}
