<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\KeyHash;

/**
 * Heading for a mail detail page. $key means one of two things,
 * disambiguated by dashboard.blade.php the same way it routes the page
 * itself: an EntryId-encoded row id (one specific send — MailDetail, subject
 * as title) or the mailable/notification FQCN (aggregate across all its
 * sends — MailClassDetail, same convention as JobHeading/QueryHeading).
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

        // Which class this send belongs to (a notification takes priority —
        // see Recorders\Mail's own $groupKey), so the breadcrumb's second
        // segment can link back to that class's own aggregate page. Null
        // for ad-hoc mail with neither (Mail::raw(), a closure view) —
        // there's no class page to link back to.
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
