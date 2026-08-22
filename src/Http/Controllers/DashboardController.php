<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Http\Headings\CommandHeading;
use LaravelMonitor\Http\Headings\ExceptionHeading;
use LaravelMonitor\Http\Headings\Heading;
use LaravelMonitor\Http\Headings\JobHeading;
use LaravelMonitor\Http\Headings\MailHeading;
use LaravelMonitor\Http\Headings\NotificationHeading;
use LaravelMonitor\Http\Headings\OutgoingHeading;
use LaravelMonitor\Http\Headings\QueryHeading;
use LaravelMonitor\Http\Headings\RequestHeading;
use LaravelMonitor\Http\Headings\ScheduleHeading;
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
    /** Tab name => the `type` column value its grouping key lives under. */
    protected const ENTRY_TYPES = [
        'requests' => 'request',
        'jobs' => 'job',
        'commands' => 'command',
        'schedule' => 'scheduled_task',
        'queries' => 'query',
        'exceptions' => 'exception',
        'notifications' => 'notification',
        'mail' => 'mail',
        'outgoing' => 'outgoing_request',
    ];

    public function __invoke(Request $request): View
    {
        $period = $request->query('period', Card::DEFAULT_PERIOD);

        if (! array_key_exists($period, Card::periods())) {
            $period = Card::DEFAULT_PERIOD;
        }

        $tab = $request->route('tab', 'overview');

        if (! in_array($tab, Nav::keys(), true)) {
            $tab = 'overview';
        }

        [$from, $to] = Card::normalizeRange($request->query('from'), $request->query('to'));

        $key = $this->resolveKey($request, $tab);
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
            'rangeMax' => now(Preferences::timezone())->format(Format::RANGE),
            'system' => $tab === 'settings' ? Settings::current() : null,
            'storageDrivers' => $tab === 'settings' ? Settings::storageDrivers() : null,
            'prefs' => $tab === 'settings' ? Preferences::all() : null,
            'localeOptions' => $tab === 'settings' ? Preferences::localeOptions() : null,
            'timezoneOptions' => $tab === 'settings' ? Preferences::timezoneOptions() : null,
            // So the header's period-switcher/custom-range links can rebuild
            // the *current* URL (whichever named route matched — plain
            // monitor.dashboard or one of the hashed detail routes) with just
            // the period/from/to swapped, instead of hardcoding monitor.dashboard.
            'currentRouteName' => $request->route()->getName(),
            'currentRouteParams' => $request->route()->parameters(),
        ]);
    }

    /**
     * The active tab's grouping key, resolved from whichever URL shape
     * matched: a numeric `{id}` (one specific occurrence — notifications/
     * mail's dual mode), a hashed `{hash}` (see Support\KeyHash), or the
     * legacy `?key=` query string for anything not behind a hashed route.
     * 404s when a hash was given but doesn't resolve to anything (a stale or
     * invalid link) — mirrors RequestDetailController et al.'s own
     * abort_unless(..., 404) for an unknown id.
     */
    protected function resolveKey(Request $request, string $tab): ?string
    {
        if (($id = $request->route('id')) !== null) {
            return (string) $id;
        }

        $hash = $request->route('hash');

        if ($hash === null) {
            return $request->query('key');
        }

        // Exceptions already group by an opaque Fingerprint hash (stored
        // directly as the entry's own key) — no reverse lookup needed.
        if ($tab === 'exceptions') {
            return $hash;
        }

        $key = app(Storage::class)->resolveKeyHash(self::ENTRY_TYPES[$tab] ?? $tab, $hash);

        abort_unless($key !== null, 404);

        return $key;
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
            'commands' => (new CommandHeading)($key),
            'schedule' => app(ScheduleHeading::class)($key),
            'exceptions' => app(ExceptionHeading::class)($key),
            'queries' => (new QueryHeading)($key),
            'notifications' => app(NotificationHeading::class)($key),
            'mail' => app(MailHeading::class)($key),
            'outgoing' => app(OutgoingHeading::class)($key),
            default => null,
        };
    }
}
