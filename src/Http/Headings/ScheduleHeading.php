<?php

namespace LaravelMonitor\Http\Headings;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Support\Cron;

/**
 * Heading for a scheduled task detail page: the full command as title (the
 * same text Recorders\ScheduledTasks\fullCommand() shows in the schedule
 * list's Task column, e.g. "php artisan app:sync-chain-data"), badged with
 * its cadence (e.g. "Every 5 minutes" — the same phrase as that list's
 * Schedule column) rather than the static "Scheduled Task" label this used
 * to carry.
 */
class ScheduleHeading
{
    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        // See ExceptionHeading for why this ignores the dashboard's selected
        // time range: a heading needs the task's latest known definition
        // regardless of which period the user is currently viewing.
        $payload = optional(
            $this->storage
                ->recent('scheduled_task', CarbonImmutable::now()->subYears(5), 1, null, $key)
                ->first()
        )->payload ?? [];

        $command = $payload['command'] ?? $key;
        $badge = Cron::describe($payload['expression'] ?? null, $payload['repeat_seconds'] ?? null);

        return new Heading(
            badge: $badge,
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $command,
            titleAttr: $command,
            pageTitle: $command,
            badgeAfter: true,
        );
    }
}
