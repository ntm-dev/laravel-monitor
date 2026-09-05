<?php

namespace LaravelMonitor;

use Carbon\CarbonImmutable;

class Entry
{
    public CarbonImmutable $timestamp;

    public function __construct(
        public string $type,
        public ?string $key = null,
        public array $payload = [],
        public ?float $duration = null,
        public ?string $subtype = null,
        public int|string|LazyValue|null $userId = null,
        public ?string $requestId = null,
        public ?float $startOffset = null,
        ?CarbonImmutable $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? CarbonImmutable::now();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'subtype' => $this->subtype !== null ? mb_substr($this->subtype, 0, 32) : null,
            'key' => $this->key !== null ? mb_substr($this->key, 0, 255) : null,
            'payload' => $this->payload,
            // Unrounded: neither Monitor::elapsedMsPrecise() nor any Recorder
            // rounds duration/startOffset before constructing an Entry, so
            // the DB's own decimal(16,6) column (see the monitor_entries
            // migration) is the one and only place these get truncated to a
            // fixed precision.
            'duration' => $this->duration,
            // Resolved here, not when the entry was recorded: a deferred user
            // id (see Monitor::lazyCurrentUserId()) is only settled once the
            // whole request/job/command has run, and toArray() is reached
            // solely from Monitor::flush() via Storage\DatabaseEntryWriter.
            'user_id' => $this->userId instanceof LazyValue ? $this->userId->resolve() : $this->userId,
            'request_id' => $this->requestId,
            'start_offset' => $this->startOffset,
            'created_at' => $this->timestamp,
        ];
    }
}
