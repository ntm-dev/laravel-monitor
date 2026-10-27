<?php

namespace LaravelMonitor\Http\Headings;

use Illuminate\Support\Str;

/**
 * Heading for a query detail page: the full SQL as title (wrapped, not truncated) and tooltip.
 */
class QueryHeading
{
    public function __invoke(string $key): Heading
    {
        return new Heading(
            badge: 'Query',
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $key,
            titleAttr: $key,
            pageTitle: Str::limit($key, 60),
            wrap: true,
        );
    }
}
