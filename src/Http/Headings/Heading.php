<?php

namespace LaravelMonitor\Http\Headings;

/**
 * Detail-page heading: badge, H1 text, tooltip and browser tab title.
 */
class Heading
{
    public function __construct(
        public readonly ?string $badge = null,
        public readonly ?string $badgeClass = null,
        public readonly ?string $heading = null,
        public readonly ?string $titleAttr = null,
        public readonly string $pageTitle = '',
        /** Wrap long text (e.g. SQL) instead of truncating it to one line. */
        public readonly bool $wrap = false,
        /** Badge after the heading, for an annotation (e.g. a cadence) rather than a classifier. */
        public readonly bool $badgeAfter = false,
        /** Extra breadcrumb segment after the tab link, e.g. "Mail › UpdateBookingFail". */
        public readonly ?string $breadcrumbLabel = null,
        public readonly ?string $breadcrumbRouteName = null,
        /** @var array<string, string>|null */
        public readonly ?array $breadcrumbRouteParams = null,
        /** False for a page about one fixed moment (e.g. one mail send). */
        public readonly bool $showPeriodSwitcher = true,
        /** False for pages that never poll, hiding the refresh countdown. */
        public readonly bool $autoRefreshes = true,
        /** Second badge after $badge, e.g. the status code next to an HTTP method. */
        public readonly ?string $secondaryBadge = null,
        /** Muted, copyable line under the heading, e.g. a full URL. */
        public readonly ?string $subtitle = null,
    ) {
    }
}
