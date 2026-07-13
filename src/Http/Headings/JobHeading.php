<?php

namespace LaravelMonitor\Http\Headings;

/**
 * Heading for a job detail page: the short class name as title, the FQCN as tooltip.
 */
class JobHeading
{
    public function __invoke(string $key): Heading
    {
        return new Heading(
            badge: 'Job',
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: class_basename($key),
            titleAttr: $key,
            pageTitle: $key,
        );
    }
}
