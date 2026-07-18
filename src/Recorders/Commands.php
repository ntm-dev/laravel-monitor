<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;

class Commands extends Recorder
{
    /**
     * A console process runs one command at a time, so a single scalar is
     * enough to bridge CommandStarting to its matching CommandFinished —
     * same reasoning as CacheInteractions' $startedAt.
     */
    protected ?float $startedAt = null;

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, [$this, 'recordStarting']);
        $events->listen(CommandFinished::class, [$this, 'recordFinished']);
    }

    public function recordStarting(CommandStarting $event): void
    {
        if ($this->isSelfReferential($event->command)) {
            return;
        }

        $this->startedAt = microtime(true);

        // Before the command's own handle() runs, so everything it triggers
        // (queries, mail, notifications, dispatched jobs) correlates onto
        // this run's own timeline — mirrors beginRequest()/beginJobAttempt().
        $this->monitor->beginCommandRun($event->command);
    }

    public function recordFinished(CommandFinished $event): void
    {
        if ($this->isSelfReferential($event->command) || $this->startedAt === null) {
            return;
        }

        $duration = round((microtime(true) - $this->startedAt) * 1000, 2);
        $this->startedAt = null;

        $this->monitor->record(
            type: 'command',
            key: $event->command,
            payload: [
                'exit_code' => $event->exitCode,
                'model_count' => $this->monitor->modelCount(),
            ],
            duration: $duration,
            subtype: $event->exitCode === 0 ? 'success' : 'failed',
        );

        $this->monitor->endCommandRun();

        // Console commands never hit the request lifecycle, persist now.
        $this->monitor->flush();
    }

    /** Monitor's own housekeeping commands shouldn't show up as recorded commands themselves. */
    protected function isSelfReferential(string $command): bool
    {
        return str_starts_with($command, 'monitor:');
    }
}
