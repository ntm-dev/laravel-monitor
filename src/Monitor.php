<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Foundation\Application;
use LaravelMonitor\Contracts\Storage;
use Throwable;

class Monitor
{
    /** @var Entry[] */
    protected array $entries = [];

    protected bool $recording = true;

    public function __construct(protected Application $app)
    {
    }

    /**
     * Buffer a new entry. It is persisted on flush (end of request/job) or
     * as soon as the buffer limit is reached.
     */
    public function record(
        string $type,
        ?string $key = null,
        array $payload = [],
        ?int $duration = null,
        ?string $subtype = null,
        int|string|null $userId = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $this->entries[] = new Entry($type, $key, $payload, $duration, $subtype, $userId);

        if (count($this->entries) >= (int) $this->app['config']->get('monitor.buffer', 200)) {
            $this->flush();
        }
    }

    public function enabled(): bool
    {
        return $this->recording && (bool) $this->app['config']->get('monitor.enabled', true);
    }

    /**
     * Persist all buffered entries through the configured storage driver.
     * Recording is paused while flushing so the storage writes themselves
     * (e.g. database queries) are never captured.
     */
    public function flush(): void
    {
        if ($this->entries === []) {
            return;
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->recording = false;

        try {
            $this->storage()->store($entries);
        } catch (Throwable) {
            // Monitoring must never take the application down.
        } finally {
            $this->recording = true;
        }
    }

    /**
     * Run a callback without recording anything it triggers.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function ignore(callable $callback): mixed
    {
        $previous = $this->recording;
        $this->recording = false;

        try {
            return $callback();
        } finally {
            $this->recording = $previous;
        }
    }

    public function stopRecording(): void
    {
        $this->recording = false;
    }

    public function startRecording(): void
    {
        $this->recording = true;
    }

    public function storage(): Storage
    {
        return $this->app->make(Storage::class);
    }
}
