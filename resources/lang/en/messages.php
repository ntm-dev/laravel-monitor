<?php

return [

    'nav' => [
        'overview' => 'Dashboard',
        'issues' => 'Issues',
        'requests' => 'Requests',
        'jobs' => 'Jobs',
        'schedule' => 'Scheduled Tasks',
        'exceptions' => 'Exceptions',
        'queries' => 'Queries',
        'notifications' => 'Notifications',
        'mail' => 'Mail',
        'cache' => 'Cache',
        'outgoing' => 'Outgoing Requests',
        'users' => 'Users',
        'logs' => 'Logs',
        'settings' => 'Settings',
        'support' => 'Support',
    ],

    'group' => [
        'activity' => 'Activity',
        'monitoring' => 'Monitoring',
    ],

    'settings' => [
        'preferences' => 'Preferences',
        'preferences_hint' => 'These settings are stored in your browser and only affect how the dashboard looks for you.',
        'environment' => 'Environment',
        'environment_hint' => 'Read-only values from config/monitor.php. Change them via your config file or environment variables.',
        'recorders' => 'Recorders',

        'theme' => 'Theme',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
        'theme_system' => 'System',
        'language' => 'Language',
        'timezone' => 'Timezone',
        'use_browser_timezone' => 'Use browser timezone',
        'save' => 'Save preferences',
        'saved' => 'Preferences saved.',

        'environment_editable_hint' => 'Overrides for config/monitor.php defaults. Saved values win; anything left at its default keeps following the config file.',
        'save_system' => 'Save settings',
        'reset' => 'Reset to defaults',
        'settings_saved' => 'Settings saved.',
        'settings_reset' => 'Reset to config defaults.',
        'periods_help' => 'Label + number of hours per row. Arbitrary ranges can still be picked from the calendar.',
        'periods_required' => 'Add at least one valid period (label and hours).',
        'add_period' => 'Add period',
        'period_label' => 'Label',
        'period_hours' => 'Hours',
        'remove' => 'Remove',
        'tz_search' => 'Search timezone…',
        'tz_no_match' => 'No timezone found.',
        'storage_note' => 'Advanced — changing the storage or dashboard path applies from the next request; the dashboard reloads at the new URL. Storage changes can hide existing data until the new table is populated.',
        'path_help' => 'The URL prefix the dashboard is served from.',
        'recorders_hint' => 'Toggle which framework events are recorded.',

        'recording' => 'Recording',
        'storage_driver' => 'Storage driver',
        'database_table' => 'Database table',
        'retention' => 'Retention',
        'dashboard_path' => 'Dashboard path',
        'dashboard_refresh' => 'Dashboard refresh',
        'periods' => 'Periods',
        'request_threshold' => 'Request threshold',
        'job_threshold' => 'Job threshold',

        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'hours' => ':count hours',
    ],

];
