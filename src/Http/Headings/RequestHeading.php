<?php

namespace LaravelMonitor\Http\Headings;

use Illuminate\Support\Str;

/**
 * Heading for a request detail page: the HTTP method as badge, the path as title.
 * The key is stored as "METHOD /path".
 */
class RequestHeading
{
    public function __invoke(string $key): Heading
    {
        return new Heading(
            badge: Str::before($key, ' '),
            badgeClass: 'bg-neutral-200 text-neutral-600 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-400 dark:shadow-neu-dark-inset',
            heading: Str::after($key, ' '),
            pageTitle: $key,
        );
    }
}
