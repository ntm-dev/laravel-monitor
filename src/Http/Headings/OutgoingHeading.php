<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\Format;
use LaravelMonitor\Support\KeyHash;

use function parse_url;

/**
 * Heading for an outgoing request page. $key is an EntryId (one call) or the
 * destination host (aggregate page).
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

        // Like the Request page: host breadcrumb, method/status badges, path, full URL below.
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
