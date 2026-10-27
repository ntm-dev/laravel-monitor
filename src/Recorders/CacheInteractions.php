<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Str;

class CacheInteractions extends Recorder
{
    /** Subtypes counted as write/delete failures on the Cache page. */
    public const FAILURE_SUBTYPES = ['write_failed', 'forget_failed'];

    /**
     * Set by the "before" event, read by the matching "after" one; cache
     * events never interleave, so one scalar suffices. Not cleared after a
     * read: a many()/putMany() batch fires one "before" event, so each key
     * measures time since the batch started.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(RetrievingKey::class, fn () => $this->startedAt = microtime(true));
        $events->listen(RetrievingManyKeys::class, fn () => $this->startedAt = microtime(true));
        $events->listen(WritingKey::class, fn () => $this->startedAt = microtime(true));
        $events->listen(WritingManyKeys::class, fn () => $this->startedAt = microtime(true));
        $events->listen(ForgettingKey::class, fn () => $this->startedAt = microtime(true));

        // storeName only exists on these events from Laravel 11; `??` avoids
        // an "Undefined property" error on Laravel 10.
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->record($event->key, 'hit', $event->storeName ?? null));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->record($event->key, 'miss', $event->storeName ?? null));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->record($event->key, 'write', $event->storeName ?? null, $event->seconds));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->record($event->key, 'forget', $event->storeName ?? null));
        $events->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->record($event->key, 'write_failed', $event->storeName ?? null, $event->seconds));
        $events->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->record($event->key, 'forget_failed', $event->storeName ?? null));
    }

    protected function record(string $key, string $interaction, ?string $storeName = null, ?int $ttl = null): void
    {
        if (Str::matchesAny($key, $this->config['ignore_keys'] ?? [])) {
            return;
        }

        // No duration when the "before" event never fired (older Laravel,
        // custom driver). round(x, 3) strips float noise from subtracting two
        // epoch timestamps (microsecond resolution).
        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 3)
            : null;

        $this->monitor->record(
            type: RecordType::Cache,
            key: $key,
            payload: array_filter([
                'store' => $storeName,
                'ttl' => $ttl,
            ], fn ($value) => $value !== null),
            duration: $duration,
            subtype: $interaction,
        );
    }
}
