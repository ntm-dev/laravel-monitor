<?php

namespace LaravelMonitor\Http\Headings;

/**
 * Heading for a command detail page: the artisan signature as title.
 */
class CommandHeading
{
    public function __invoke(string $key): Heading
    {
        return new Heading(
            badge: 'Command',
            badgeClass: 'bg-neutral-200 text-neutral-600 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-400 dark:shadow-neu-dark-inset',
            heading: $key,
            titleAttr: $key,
            pageTitle: $key,
        );
    }
}
