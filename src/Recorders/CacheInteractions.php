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

class CacheInteractions extends Recorder
{
    /**
     * Subtypes counted as write/delete failures on the Cache page — mirrors
     * Nightwatch's Failures chart (only mutating operations can fail; hits
     * and misses can't).
     */
    public const FAILURE_SUBTYPES = ['write_failed', 'forget_failed'];

    /**
     * When the in-flight cache operation started, set by whichever "before"
     * event Laravel fires just ahead of the store call and read back by the
     * matching "after" event below — same technique as Nightwatch's
     * CacheEventSensor. Cache events fire synchronously around a single
     * driver call with nothing else able to interleave, so one scalar is
     * enough; it isn't keyed by cache key. Left unset (not reset to null)
     * after a read, matching Nightwatch: a Cache::many()/putMany() batch
     * only fires one "before" event for the whole batch, so every
     * CacheHit/CacheMissed it produces measures elapsed-since-batch-start
     * rather than a true per-key duration — an accepted approximation, not
     * something worth special-casing here.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(RetrievingKey::class, fn () => $this->startedAt = microtime(true));
        $events->listen(RetrievingManyKeys::class, fn () => $this->startedAt = microtime(true));
        $events->listen(WritingKey::class, fn () => $this->startedAt = microtime(true));
        $events->listen(WritingManyKeys::class, fn () => $this->startedAt = microtime(true));
        $events->listen(ForgettingKey::class, fn () => $this->startedAt = microtime(true));

        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->record($event->key, 'hit'));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->record($event->key, 'miss'));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->record($event->key, 'write'));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->record($event->key, 'forget'));
        $events->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->record($event->key, 'write_failed'));
        $events->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->record($event->key, 'forget_failed'));
    }

    protected function record(string $key, string $interaction): void
    {
        if ($this->matchesAny($key, $this->config['ignore_keys'] ?? [])) {
            return;
        }

        // Laravel versions/stores that never dispatch the "before" event
        // (older Laravel, a custom driver) simply leave this null — falls
        // back to today's behaviour (no duration) instead of guessing.
        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 3)
            : null;

        $this->monitor->record(
            type: 'cache',
            key: $key,
            duration: $duration,
            subtype: $interaction,
        );
    }
}
