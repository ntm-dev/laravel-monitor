<?php

namespace LaravelMonitor\Support;

/**
 * One bar on the Request Detail timeline: the root, a lifecycle phase
 * (an ExecutionStage value), or a correlated event (see Timeline::EVENT_TYPES).
 */
class TimelineEntry
{
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public int|float $start,
        /** Null when the event has no duration (e.g. an exception), as opposed to a measured zero. */
        public int|float|null $duration,
        public ?string $parentId = null,
        public array $metadata = [],
        public int $lane = 0,
    ) {
    }

    public function end(): int|float
    {
        return $this->start + ($this->duration ?? 0);
    }
}
