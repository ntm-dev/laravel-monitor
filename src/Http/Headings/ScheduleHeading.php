<?php

namespace LaravelMonitor\Http\Headings;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\Cron;

/**
 * Heading for a scheduled task page: the full command as title, its cadence
 * (e.g. "Every 5 minutes") as a trailing badge.
 */
class ScheduleHeading
{
    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        // Latest definition regardless of the selected time range.
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
