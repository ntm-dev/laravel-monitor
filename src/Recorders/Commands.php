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

        // A command-based scheduled task (Schedule::command()) always runs
        // this command in a *separate* `php artisan` subprocess, even when
        // scheduled to run "in the foreground" — see
        // Illuminate\Console\Scheduling\Event::execute(). The scheduled
        // task's own id rides across that process boundary via Laravel's
        // own Context dehydration/hydration (see
        // Monitor::beginScheduledTaskRun()), so it's already available here,
        // in the fresh subprocess, before any application code has run.
        // Adopting it as this run's own id — instead of minting a fresh one
        // — is what nests this command (and everything it triggers: queries,
        // mail, dispatched jobs, ...) onto the scheduled task's own timeline
        // rather than starting an unrelated one of its own.
        $inheritedId = $this->monitor->inheritedScheduledTaskRunId();

        // Before the command's own handle() runs, so everything it triggers
        // (queries, mail, notifications, dispatched jobs) correlates onto
        // this run's own timeline — mirrors beginRequest()/beginJobAttempt().
        $this->monitor->beginCommandRun($event->command, $inheritedId);
    }

    public function recordFinished(CommandFinished $event): void
    {
        if ($this->isSelfReferential($event->command) || $this->startedAt === null) {
            return;
        }

        $duration = round((microtime(true) - $this->startedAt) * 1000, 2);
        $this->startedAt = null;

        // Closes out the 'action' phase (everything since CommandStarting)
        // and opens 'terminating' — mirrors markTerminating() for requests,
        // just with no controller/render/unwinding steps of its own to close
        // first. Must happen before record() below so the entry's own
        // finalizePendingCommand() (run from flush() just after) closes
        // 'terminating' against a phase list that already has 'bootstrap'
        // and 'action' in it.
        $this->monitor->markCommandTerminating();

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

        // Console commands never hit the request lifecycle, persist now.
        // Before endCommandRun(): flush() finalizes the pending 'command'
        // entry (phases, full-lifecycle duration) using $this->command,
        // which endCommandRun() would otherwise have already cleared.
        $this->monitor->flush();

        $this->monitor->endCommandRun();
    }

    /** Monitor's own housekeeping commands shouldn't show up as recorded commands themselves. */
    protected function isSelfReferential(string $command): bool
    {
        return str_starts_with($command, 'monitor:');
    }
}
