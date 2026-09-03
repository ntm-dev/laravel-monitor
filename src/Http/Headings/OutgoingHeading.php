<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;

/**
 * Heading for an outgoing (HTTP client) request detail page. $key means one
 * of two things, disambiguated by dashboard.blade.php the same way it routes
 * the page itself: a numeric database id (one specific call — OutgoingDetail,
 * method+url as heading) or the destination host (aggregate across all calls
 * to it — OutgoingDomainDetail, same convention as MailHeading/JobHeading).
 */
class OutgoingHeading
{
    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        if (! ctype_digit($key)) {
            return new Heading(
                heading: $key,
                titleAttr: $key,
                pageTitle: $key,
            );
        }

        $entry = $this->storage->findById((int) $key, 'outgoing_request');

        if ($entry === null) {
            return new Heading(pageTitle: 'Outgoing Request');
        }

        $method = $entry->payload['method'] ?? $entry->subtype;
        $url = $entry->payload['url'] ?? $entry->key;

        return new Heading(
            badge: $method,
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $url,
            titleAttr: $url,
            pageTitle: $method.' '.$url,
        );
    }
}
