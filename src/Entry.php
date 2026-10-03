<?php

namespace LaravelMonitor;

use Carbon\CarbonImmutable;

class Entry
{
    public CarbonImmutable $timestamp;

    public function __construct(
        public string $type,
        public string|LazyValue|null $key = null,
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

    /**
     * Deferred values (e.g. Support\Trace::capture()) are settled here, at
     * flush, rather than on the recording hot path. One that resolves to
     * null is dropped, the same as a null the recorder filtered out itself.
     *
     * @return array<string, mixed>
     */
    protected function resolvedPayload(): array
    {
        $payload = [];

        foreach ($this->payload as $name => $value) {
            if ($value instanceof LazyValue) {
                $value = $value->resolve();

                if ($value === null) {
                    continue;
                }
            }

            $payload[$name] = $value;
        }

        return $payload;
    }

    public function toArray(): array
    {
        $key = $this->key instanceof LazyValue ? $this->key->resolve() : $this->key;

        return [
            'type' => $this->type,
            'subtype' => $this->subtype !== null ? mb_substr($this->subtype, 0, 32) : null,
            'key' => $key !== null ? mb_substr($key, 0, 255) : null,
            'payload' => $this->resolvedPayload(),
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
