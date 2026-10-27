<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\KeyHash;

/**
 * Heading for a mail page. $key is an EntryId (one send, subject as title) or
 * the mailable/notification class (aggregate page).
 */
class MailHeading
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

        $entry = $this->storage->findById($id, 'mail');

        if ($entry === null) {
            return new Heading(pageTitle: 'Mail');
        }

        $subject = $entry->payload['subject'] ?? $entry->key;
        $to = $entry->payload['to'] ?? null;

        // Class for the breadcrumb link (notification first, as Recorders\Mail groups);
        // null for ad-hoc mail such as Mail::raw().
        $classKey = $entry->payload['notification'] ?? $entry->payload['mailable'] ?? null;

        return new Heading(
            heading: $subject,
            titleAttr: $to,
            pageTitle: $subject,
            breadcrumbLabel: $classKey !== null ? class_basename($classKey) : null,
            breadcrumbRouteName: $classKey !== null ? 'monitor.mail.show' : null,
            breadcrumbRouteParams: $classKey !== null ? ['hash' => KeyHash::for($classKey)] : null,
            showPeriodSwitcher: false,
            autoRefreshes: false,
        );
    }
}
