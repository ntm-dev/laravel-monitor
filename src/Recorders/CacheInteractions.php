<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;

class CacheInteractions extends Recorder
{
    /**
     * Subtypes counted as write/delete failures on the Cache page — mirrors
     * Nightwatch's Failures chart (only mutating operations can fail; hits
     * and misses can't).
     */
    public const FAILURE_SUBTYPES = ['write_failed', 'forget_failed'];

    public function register(Dispatcher $events): void
    {
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

        $this->monitor->record(
            type: 'cache',
            key: $key,
            subtype: $interaction,
        );
    }
}
