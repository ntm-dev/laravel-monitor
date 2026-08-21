<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\Storage;

/**
 * Heading for a notification detail page. $key means one of two things,
 * disambiguated by dashboard.blade.php the same way it routes the page
 * itself: a numeric database id (one specific send — NotificationDetail) or
 * the notification's FQCN (aggregate across all its sends —
 * NotificationClassDetail, same convention as JobHeading/QueryHeading).
 */
class NotificationHeading
{
    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        if (! ctype_digit($key)) {
            return new Heading(
                heading: $key,
                titleAttr: $key,
                pageTitle: class_basename($key),
            );
        }

        $entry = $this->storage->findById((int) $key, 'notification');

        if ($entry === null) {
            return new Heading(pageTitle: 'Notification');
        }

        $class = $entry->payload['notification'] ?? $entry->key;
        $channel = $entry->payload['channel'] ?? $entry->subtype;

        return new Heading(
            badge: $channel,
            badgeClass: 'bg-neutral-200 text-neutral-600 shadow-neu-inset dark:bg-neutral-800 dark:text-neutral-400 dark:shadow-neu-dark-inset',
            heading: $class,
            titleAttr: $class,
            pageTitle: class_basename($class),
        );
    }
}
