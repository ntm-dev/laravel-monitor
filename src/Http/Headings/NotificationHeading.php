<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\EntryId;

/**
 * Heading for a notification page. $key is an EntryId (one send) or the
 * notification class (aggregate page).
 */
class NotificationHeading
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
                pageTitle: class_basename($key),
            );
        }

        $entry = $this->storage->findById($id, 'notification');

        if ($entry === null) {
            return new Heading(pageTitle: 'Notification');
        }

        $class = $entry->payload['notification'] ?? $entry->key;
        $channel = $entry->payload['channel'] ?? $entry->subtype;

        return new Heading(
            badge: $channel,
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $class,
            titleAttr: $class,
            pageTitle: class_basename($class),
        );
    }
}
