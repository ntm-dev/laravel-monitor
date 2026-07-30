<?php

namespace LaravelMonitor\Http\Headings;

/**
 * Heading for a scheduled task detail page: the task/command signature as title.
 */
class ScheduleHeading
{
    public function __invoke(string $key): Heading
    {
        return new Heading(
            badge: 'Scheduled Task',
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $key,
            titleAttr: $key,
            pageTitle: $key,
        );
    }
}
