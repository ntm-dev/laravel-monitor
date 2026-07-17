<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

class ScheduledTasks extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskFinished::class, [$this, 'recordFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'recordFailed']);
        $events->listen(ScheduledTaskSkipped::class, [$this, 'recordSkipped']);
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, 'finished', round($event->runtime * 1000, 2));
    }

    public function recordFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed', null, Str::limit($event->exception->getMessage(), 500));
    }

    public function recordSkipped(ScheduledTaskSkipped $event): void
    {
        $this->record($event->task, 'skipped');
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
