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
     * Resolves deferred payload values at flush; one resolving to null is dropped.
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
            'duration' => $this->duration,
            // Resolved at flush, once the whole request/job/command has run.
            'user_id' => $this->userId instanceof LazyValue ? $this->userId->resolve() : $this->userId,
            'request_id' => $this->requestId,
            'start_offset' => $this->startOffset,
            'created_at' => $this->timestamp,
        ];
    }
}
