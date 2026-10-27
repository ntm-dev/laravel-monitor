<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use LaravelMonitor\Support\Host;
use LaravelMonitor\Support\RecordType;

use function memory_get_peak_usage;
use function preg_replace;
use function str_contains;
use function str_replace;
use function trim;

class ScheduledTasks extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'recordStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'recordFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'recordFailed']);
        $events->listen(ScheduledTaskSkipped::class, [$this, 'recordSkipped']);
    }

    /** Starts this task's run (and timeline) before its body executes. */
    public function recordStarting(ScheduledTaskStarting $event): void
    {
        $this->monitor->beginScheduledTaskRun();

        // Peak memory per task: schedule:run/schedule:work run several per process.
        $this->resetPeakMemoryUsage();
    }

    public function recordFinished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, 'finished', $event->runtime * 1000);
        $this->monitor->endScheduledTaskRun();
    }

    public function recordFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed', null, Str::limit($event->exception->getMessage(), 500));
        $this->monitor->endScheduledTaskRun();
    }

    public function recordSkipped(ScheduledTaskSkipped $event): void
    {
        // Skipped tasks get no ScheduledTaskStarting, so begin/end the run here.
        $this->monitor->beginScheduledTaskRun();
        $this->record($event->task, 'skipped');
        $this->monitor->endScheduledTaskRun();
    }

    protected function record(ScheduledEvent $task, string $status, ?float $duration = null, ?string $error = null): void
    {
        $this->monitor->record(
            type: RecordType::ScheduledTask,
            key: self::name($task),
            payload: array_filter([
                'command' => $this->fullCommand($task),
                'description' => $task->description,
                'expression' => $task->expression,
                'timezone' => $task->timezone instanceof \DateTimeZone ? $task->timezone->getName() : $task->timezone,
                'without_overlapping' => $task->withoutOverlapping,
                'on_one_server' => $task->onOneServer,
                'run_in_background' => $task->runInBackground,
                'even_in_maintenance_mode' => $task->evenInMaintenanceMode,
                'repeat_seconds' => $task->repeatSeconds,
                // Omitted for a skipped run: the task body never executed.
                'server' => $status !== 'skipped' ? Host::name() : null,
                'peak_memory' => $status !== 'skipped' ? memory_get_peak_usage(true) : null,
                'error' => $error,
            ]),
            duration: $duration,
            subtype: $status,
        );

        // The schedule runs outside a request, persist immediately.
        $this->monitor->flush();
    }

    /**
     * Stable grouping key for the task. Not fullCommand(): the resolved php
     * binary can differ between runs. Public so Livewire\Schedule can match
     * keys against the currently registered Schedule::events().
     */
    public static function name(ScheduledEvent $task): string
    {
        $command = $task->command ?? '';

        // Strip the php binary and artisan path for readability.
        if (str_contains($command, 'artisan')) {
            $command = trim(Str::after($command, 'artisan'), " '\"");
        }

        return $command !== '' ? $command : ($task->description ?: 'closure');
    }

    /**
     * The command as `schedule:list` prints it ("php artisan app:sync-data"),
     * or null for a closure. Reimplements Event::normalizeCommand() (Laravel
     * 11+ only); done here because the php binary resolves differently in the
     * web process.
     */
    protected function fullCommand(ScheduledEvent $task): ?string
    {
        if ($task->command === null) {
            return null;
        }

        return str_replace(
            [ConsoleApplication::phpBinary(), ConsoleApplication::artisanBinary()],
            ['php', preg_replace("#['\"]#", '', ConsoleApplication::artisanBinary())],
            $task->command,
        );
    }
}
