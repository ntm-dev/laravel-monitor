<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Contracts\Storage;

/**
 * Heading for a mail detail page. $key means one of two things,
 * disambiguated by dashboard.blade.php the same way it routes the page
 * itself: a numeric database id (one specific send — MailDetail, subject as
 * title) or the mailable/notification FQCN (aggregate across all its sends
 * — MailClassDetail, same convention as JobHeading/QueryHeading).
 */
class MailHeading
{
    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        if (! ctype_digit($key)) {
            return new Heading(
                heading: class_basename($key),
                titleAttr: $key,
                pageTitle: class_basename($key),
            );
        }

        $entry = $this->storage->findById((int) $key, 'mail');

        if ($entry === null) {
            return new Heading(pageTitle: 'Mail');
        }

        $subject = $entry->payload['subject'] ?? $entry->key;
        $to = $entry->payload['to'] ?? null;

        return new Heading(
            heading: $subject,
            titleAttr: $to,
            pageTitle: $subject,
        );
    }
}
