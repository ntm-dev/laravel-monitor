<?php

namespace LaravelMonitor\Http\Headings;

use Illuminate\Support\Str;
use LaravelMonitor\Recorders\Requests;

/**
 * Heading for a request route page: method badge, path title. Keys are "METHOD /path",
 * except the merged bare Requests::UNMATCHED_ROUTE row (DatabaseAggregateStorage::routeStats()).
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
