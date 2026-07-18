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
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $key,
            titleAttr: $key,
            pageTitle: $key,
        );
    }
}
