<?php

namespace LaravelMonitor\Http\Headings;

use Illuminate\Support\Str;
use LaravelMonitor\Recorders\Requests;

/**
 * Heading for a request detail page: the HTTP method as badge, the path as title.
 * The key is stored as "METHOD /path", except for the merged Unmatched Route
 * row (see DatabaseStorage::routeStats()/resolveKeyHash()), whose bare
 * Requests::UNMATCHED_ROUTE key carries no method to split out.
 */
class RequestHeading
{
    public function __invoke(string $key): Heading
    {
        if ($key === Requests::UNMATCHED_ROUTE) {
            $label = __('monitor::messages.common.unmatched_route');

            return new Heading(
                badge: 'ANY',
                badgeClass: 'bg-neutral-200/70 text-neutral-600',
                heading: $label,
                pageTitle: $label,
            );
        }

        return new Heading(
            badge: Str::before($key, ' '),
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: Str::after($key, ' '),
            pageTitle: $key,
        );
    }
}
