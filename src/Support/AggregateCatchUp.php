<?php

namespace LaravelMonitor\Support;

use BadMethodCallException;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Rolls monitor_aggregates up to the range a dashboard page just had to
 * scan raw, once the response has been sent — so an app that never
 * scheduled `monitor:aggregate` still ends up on the fast path without the
 * viewer waiting on it. Registered at most once per request, for the
 * oldest `since` asked for.
 */
class AggregateCatchUp
{
    protected const LOCK = 'monitor:aggregate-catch-up';

    protected const LOCK_SECONDS = 120;

    protected ?DateTimeInterface $since = null;

    public function __construct(
        protected Application $app,
        protected Aggregator $aggregator,
        protected CacheFactory $cache,
    ) {
    }

    public function defer(DateTimeInterface $since): void
    {
        if (! config('monitor.aggregates.catch_up', true)) {
            return;
        }

        if ($this->since === null) {
            $this->app->terminating($this->run(...));
        }

        if ($this->since === null || $since < $this->since) {
            $this->since = $since;
        }
    }

    protected function run(): void
    {
        try {
            $lock = $this->lock();

            if ($lock !== null && ! $lock->get()) {
                return;
            }

            try {
                $this->aggregator->catchUp(
                    $this->since,
                    (int) config('monitor.aggregates.period', 60),
                    (int) config('monitor.aggregates.catch_up_buckets', 30),
                );
            } finally {
                $lock?->release();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Null when the cache store can't lock — the upserts are idempotent, so overlapping runs only cost time. */
    protected function lock(): ?Lock
    {
        try {
            return $this->cache->store()->lock(self::LOCK, self::LOCK_SECONDS);
        } catch (BadMethodCallException) {
            return null;
        }
    }
}
