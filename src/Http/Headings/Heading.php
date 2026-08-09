<?php

namespace LaravelMonitor\Http\Headings;

/**
 * Presentation-ready heading for a detail page: badge, H1 text, its title
 * attribute (tooltip) and the browser tab title.
 */
class Heading
{
    public function __construct(
        public readonly ?string $badge = null,
        public readonly ?string $badgeClass = null,
        public readonly ?string $heading = null,
        public readonly ?string $titleAttr = null,
        public readonly string $pageTitle = '',
        /** Wrap onto multiple lines instead of single-line CSS truncation — for long, meaningful text like SQL where clipping loses information. */
        public readonly bool $wrap = false,
        /** Render the badge after the heading text instead of before it — for a badge that annotates the heading (e.g. a schedule's cadence) rather than classifying it (e.g. an HTTP method). */
        public readonly bool $badgeAfter = false,
    ) {
    }
}
