<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

class ScheduledTasks extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'recordStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'recordFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'recordFailed']);
        $events->listen(ScheduledTaskSkipped::class, [$this, 'recordSkipped']);
    }

    /**
     * Mints this run's own id before the task itself executes — mirrors
     * beginJobAttempt()/beginCommandRun() — so everything it triggers
     * correlates onto this one task's own timeline (see
     * Monitor::beginScheduledTaskRun() for how a command-based task's own
     * `php artisan` subprocess picks this same id back up). Skipped tasks
     * never reach here (ScheduledTaskSkipped is dispatched directly by
     * ScheduleRunCommand instead of via Event::run()), so recordSkipped()
     * begins/ends its own run around the single record() call.
     */
    public function recordStarting(ScheduledTaskStarting $event): void
    {
        $this->monitor->beginScheduledTaskRun();
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, 'finished', round($event->runtime * 1000, 2));
        $this->monitor->endScheduledTaskRun();
    }

    public function recordFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed', null, Str::limit($event->exception->getMessage(), 500));
        $this->monitor->endScheduledTaskRun();
    }

    public function recordSkipped(ScheduledTaskSkipped $event): void
    {
        // No matching ScheduledTaskStarting fires for a skipped task (see
        // ScheduleRunCommand) — begin/end its own run right around this one
        // entry rather than relying on recordStarting() having already done so.
        $this->monitor->beginScheduledTaskRun();
        $this->record($event->task, 'skipped');
        $this->monitor->endScheduledTaskRun();
    }

    protected function record(ScheduledEvent $task, string $status, ?float $duration = null, ?string $error = null): void
    {
        $this->monitor->record(
            type: 'scheduled_task',
            key: $this->name($task),
            payload: array_filter([
                'command' => $this->name($task),
                'description' => $task->description,
                'expression' => $task->expression,
                'timezone' => $task->timezone instanceof \DateTimeZone ? $task->timezone->getName() : $task->timezone,
                'without_overlapping' => $task->withoutOverlapping,
                'run_in_background' => $task->runInBackground,
                'even_in_maintenance_mode' => $task->evenInMaintenanceMode,
                'repeat_seconds' => $task->repeatSeconds,
                'error' => $error,
            ]),
            duration: $duration,
            subtype: $status,
        );

        // The schedule runs outside a request, persist immediately.
        $this->monitor->flush();
    }

    protected function name(ScheduledEvent $task): string
    {
        $command = $task->command ?? '';

        // Strip the php binary and artisan path for readability.
        if (str_contains($command, 'artisan')) {
            $command = trim(Str::after($command, 'artisan'), " '\"");
        }

        return $command !== '' ? $command : ($task->description ?: 'closure');
    }
}
