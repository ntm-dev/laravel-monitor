<?php

namespace LaravelMonitor\Support;

/**
 * Rows for the read-only Settings page.
 */
class SettingsPage
{
    /**
     * @return array{general: list<array{0: string, 1: string, 2: bool|null}>, recorders: list<array{name: string, enabled: bool}>}
     */
    public static function rows(): array
    {
        return [
            'general' => [
                ['Recording', config('monitor.enabled') ? 'Enabled' : 'Disabled', (bool) config('monitor.enabled')],
                ['Storage driver', config('monitor.storage.driver', 'database'), null],
                ['Database table', config('monitor.storage.database.table', 'monitor_entries'), null],
                ['Retention', config('monitor.retention.hours', 168).' hours', null],
                ['Dashboard path', '/'.trim(config('monitor.path', 'monitor'), '/'), null],
                ['Dashboard refresh', config('monitor.refresh', 10).'s', null],
                ['Periods', implode(', ', array_keys(config('monitor.periods', []))), null],
                ['Request threshold', config('monitor.thresholds.request', 1000).'ms', null],
                ['Job threshold', config('monitor.thresholds.job', 1000).'ms', null],
            ],
            'recorders' => collect(config('monitor.recorders', []))
                ->map(fn ($options, $recorder) => [
                    'name' => class_basename($recorder),
                    'enabled' => (bool) ($options['enabled'] ?? true),
                ])
                ->values()
                ->all(),
        ];
    }
}
