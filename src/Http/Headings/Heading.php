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
        /** An extra breadcrumb segment between the tab link and the heading below it — e.g. "Mail › UpdateBookingFail", linking back to that class's aggregate page. Null renders just the plain tab link every other detail page has. */
        public readonly ?string $breadcrumbLabel = null,
        public readonly ?string $breadcrumbRouteName = null,
        /** @var array<string, string>|null */
        public readonly ?array $breadcrumbRouteParams = null,
        /** False for a page pinned to one already-fixed moment (e.g. one mail send) — a period range has nothing to filter there. */
        public readonly bool $showPeriodSwitcher = true,
        /** False for the same fixed-moment pages as $showPeriodSwitcher — nothing on them ever polls, so the sidebar's refresh ring would just count down to a refresh that never happens. */
        public readonly bool $autoRefreshes = true,
    ) {
    }
}
