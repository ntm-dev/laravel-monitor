<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;

use function parse_url;

/**
 * Heading for an outgoing (HTTP client) request detail page. $key means one
 * of two things, disambiguated by dashboard.blade.php the same way it routes
 * the page itself: an EntryId-encoded row id (one specific call —
 * OutgoingDetail, method+url as heading) or the destination host (aggregate
 * across all calls to it — OutgoingDomainDetail, same convention as
 * MailHeading/JobHeading).
 */
class OutgoingHeading
{
    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        $id = EntryId::decode($key);

        if ($id === null) {
            return new Heading(
                heading: $key,
                titleAttr: $key,
                pageTitle: $key,
            );
        }

        $entry = $this->storage->findById($id, 'outgoing_request');

        if ($entry === null) {
            return new Heading(pageTitle: 'Outgoing Request');
        }

        $method = $entry->payload['method'] ?? $entry->subtype;
        $url = $entry->payload['url'] ?? $entry->key;
        $status = $entry->payload['status'] ?? null;

        // Same order as the Request page: group (the host) › method + status
        // badges › path, with the full URL underneath.
        $path = parse_url($url, PHP_URL_PATH);
        $path = $path === null || $path === false || $path === '' ? '/' : $path;

        return new Heading(
            badge: $method,
            badgeClass: $status !== null
                ? Format::statusBadgeClass((int) $status)
                : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
            heading: $path,
            titleAttr: $path,
            pageTitle: $method.' '.$url,
            breadcrumbLabel: $entry->key,
            breadcrumbRouteName: 'monitor.outgoing.show',
            breadcrumbRouteParams: ['hash' => KeyHash::for($entry->key)],
            secondaryBadge: (string) ($status ?? '—'),
            subtitle: $url,
            showPeriodSwitcher: false,
            autoRefreshes: false,
        );
    }
}
