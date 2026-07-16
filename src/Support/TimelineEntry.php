<?php

namespace LaravelMonitor\Support;

/**
 * One bar on the Request Detail timeline — either a lifecycle phase
 * (bootstrap/middleware/controller/render/sending/terminating) or a
 * correlated event (query/cache/mail/notification/queue/http). Kept
 * data-driven so new event types only need an entry in Timeline::EVENT_TYPES,
 * not new Blade branches.
 */
class TimelineEntry
{
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public int|float $start,
        public int|float $duration,
        public ?string $parentId = null,
        public array $metadata = [],
        public int $lane = 0,
    ) {
    }

    public function end(): int|float
    {
        return $this->start + $this->duration;
    }
}
