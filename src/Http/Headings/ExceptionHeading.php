<?php

namespace LaravelMonitor\Http\Headings;

use Carbon\CarbonImmutable;
use LaravelMonitor\Contracts\Storage;

/**
 * Heading for an exception detail page: the message as both title and tooltip
 * (no badge — the handled/unhandled status lives in the trace card), while the
 * exception class names the browser tab.
 */
class ExceptionHeading
{
    public function __construct(protected Storage $storage)
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
        );
    }
}
