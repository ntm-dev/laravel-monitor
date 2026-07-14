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
        public ?int $duration = null,
        public ?string $subtype = null,
        public int|string|null $userId = null,
        public ?string $requestId = null,
        public ?int $startOffset = null,
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
            'duration' => $this->duration,
            'user_id' => $this->userId,
            'request_id' => $this->requestId,
            'start_offset' => $this->startOffset,
            'created_at' => $this->timestamp,
        ];
    }
}
