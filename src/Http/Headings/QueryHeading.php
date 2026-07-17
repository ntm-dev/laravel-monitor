<?php

namespace LaravelMonitor\Http\Headings;

use Illuminate\Support\Str;

/**
 * Heading for a query detail page: the SQL as both title and tooltip
 * (truncated by the header's own CSS, not here — the full text stays in the
 * DOM for the title attribute and for copy/search).
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
