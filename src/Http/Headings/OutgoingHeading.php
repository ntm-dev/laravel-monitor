<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\Storage;

/**
 * Heading for an outgoing (HTTP client) request detail page. Unlike
 * Mail/NotificationHeading there's no aggregate ("class") mode — $key is
 * always the entry's own database id, since outgoing requests only have a
 * per-occurrence detail page (see Livewire\OutgoingDetail).
 */
class OutgoingHeading
{
    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        if (! ctype_digit($key)) {
            return new Heading(pageTitle: 'Outgoing Request');
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
