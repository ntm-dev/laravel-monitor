<?php

namespace LaravelMonitor\Http\Headings;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\TimelineStorage;

/**
 * Heading for an exception detail page: the message as title and tooltip, the
 * class as tab title, no badge. Reads the latest entry regardless of the selected range.
 */
class ExceptionHeading
{
    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $key): Heading
    {
        $payload = optional(
            $this->storage
                ->recent('exception', CarbonImmutable::now()->subYears(5), 1, null, $key)
                ->first()
        )->payload ?? [];

        $class = $payload['class'] ?? null;
        $message = $payload['message'] ?? null;

        return new Heading(
            heading: filled($message) ? $message : null,
            titleAttr: filled($message) ? $message : null,
            pageTitle: $class ? class_basename($class) : $key,
            showPeriodSwitcher: false,
        );
    }
}
