<?php

namespace LaravelMonitor\Support;

/**
 * Single source of truth for dashboard tabs: sidebar entries, groups,
 * icons and the Livewire component backing each tab.
 */
class Nav
{
    public const GROUP_ACTIVITY = 'Activity';

    public const GROUP_MONITORING = 'Monitoring';

    /**
     * @return array<string, array{label: string, group: string|null, icon: string, component: string|null}>
     */
    public static function tabs(): array
    {
        $activity = __('monitor::messages.group.activity');
        $monitoring = __('monitor::messages.group.monitoring');

        return [
            'overview' => ['label' => __('monitor::messages.nav.overview'), 'group' => null, 'icon' => Icons::DASHBOARD, 'component' => null],
            'issues' => ['label' => __('monitor::messages.nav.issues'), 'group' => null, 'icon' => Icons::ISSUES, 'component' => 'monitor.issues'],
            'requests' => ['label' => __('monitor::messages.nav.requests'), 'group' => $activity, 'icon' => Icons::REQUESTS, 'component' => 'monitor.requests'],
            'jobs' => ['label' => __('monitor::messages.nav.jobs'), 'group' => $activity, 'icon' => Icons::JOBS, 'component' => 'monitor.jobs'],
            'schedule' => ['label' => __('monitor::messages.nav.schedule'), 'group' => $activity, 'icon' => Icons::SCHEDULE, 'component' => 'monitor.schedule'],
            'exceptions' => ['label' => __('monitor::messages.nav.exceptions'), 'group' => $activity, 'icon' => Icons::EXCEPTIONS, 'component' => 'monitor.exceptions'],
            'queries' => ['label' => __('monitor::messages.nav.queries'), 'group' => $activity, 'icon' => Icons::QUERIES, 'component' => 'monitor.queries'],
            'notifications' => ['label' => __('monitor::messages.nav.notifications'), 'group' => $activity, 'icon' => Icons::NOTIFICATIONS, 'component' => 'monitor.notifications'],
            'mail' => ['label' => __('monitor::messages.nav.mail'), 'group' => $activity, 'icon' => Icons::MAIL, 'component' => 'monitor.mail'],
            'cache' => ['label' => __('monitor::messages.nav.cache'), 'group' => $activity, 'icon' => Icons::CACHE, 'component' => 'monitor.cache'],
            'outgoing' => ['label' => __('monitor::messages.nav.outgoing'), 'group' => $activity, 'icon' => Icons::OUTGOING, 'component' => 'monitor.outgoing-requests'],
            'users' => ['label' => __('monitor::messages.nav.users'), 'group' => $monitoring, 'icon' => Icons::USERS, 'component' => 'monitor.users'],
            'logs' => ['label' => __('monitor::messages.nav.logs'), 'group' => $monitoring, 'icon' => Icons::LOGS, 'component' => 'monitor.logs'],
            'settings' => ['label' => __('monitor::messages.nav.settings'), 'group' => 'footer', 'icon' => Icons::SETTINGS, 'component' => null],
        ];
    }

    /**
     * Tab keys accepted by the dashboard route.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::tabs());
    }

    /**
     * Tabs split into sidebar groups and footer entries.
     *
     * @return array{0: array<string, array<string, array<string, mixed>>>, 1: array<string, array<string, mixed>>}
     */
    public static function grouped(): array
    {
        $groups = [];
        $footer = [];

        foreach (self::tabs() as $key => $item) {
            if ($item['group'] === 'footer') {
                $footer[$key] = $item;
            } else {
                $groups[$item['group']][$key] = $item;
            }
        }

        return [$groups, $footer];
    }
}
