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
        return [
            'overview' => ['label' => 'Dashboard', 'group' => null, 'icon' => Icons::DASHBOARD, 'component' => null],
            'issues' => ['label' => 'Issues', 'group' => null, 'icon' => Icons::ISSUES, 'component' => 'monitor.issues'],
            'requests' => ['label' => 'Requests', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::REQUESTS, 'component' => 'monitor.requests'],
            'jobs' => ['label' => 'Jobs', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::JOBS, 'component' => 'monitor.jobs'],
            'schedule' => ['label' => 'Scheduled Tasks', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::SCHEDULE, 'component' => 'monitor.schedule'],
            'exceptions' => ['label' => 'Exceptions', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::EXCEPTIONS, 'component' => 'monitor.exceptions'],
            'queries' => ['label' => 'Queries', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::QUERIES, 'component' => 'monitor.slow-queries'],
            'notifications' => ['label' => 'Notifications', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::NOTIFICATIONS, 'component' => 'monitor.notifications'],
            'mail' => ['label' => 'Mail', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::MAIL, 'component' => 'monitor.mail'],
            'cache' => ['label' => 'Cache', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::CACHE, 'component' => 'monitor.cache'],
            'outgoing' => ['label' => 'Outgoing Requests', 'group' => self::GROUP_ACTIVITY, 'icon' => Icons::OUTGOING, 'component' => 'monitor.outgoing-requests'],
            'users' => ['label' => 'Users', 'group' => self::GROUP_MONITORING, 'icon' => Icons::USERS, 'component' => 'monitor.users'],
            'logs' => ['label' => 'Logs', 'group' => self::GROUP_MONITORING, 'icon' => Icons::LOGS, 'component' => 'monitor.logs'],
            'settings' => ['label' => 'Settings', 'group' => 'footer', 'icon' => Icons::SETTINGS, 'component' => null],
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
