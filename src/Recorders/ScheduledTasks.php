<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

use function gethostname;
use function memory_get_peak_usage;
use function memory_reset_peak_usage;
use function str_contains;
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

        // Fires right before the task's own body runs — see ScheduleRunCommand::runEvent(),
        // which dispatches this synchronously ahead of $event->run(). A cron-triggered
        // `schedule:run` can run several due tasks in one PHP process, and `schedule:work`
        // never restarts its process at all, so without this reset, record()'s
        // memory_get_peak_usage() below would report the cumulative peak across every task
        // that process has ever run, not this one's own — same reasoning as
        // Recorders\Jobs::resetPeakMemory().
        memory_reset_peak_usage();
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
                'command' => $this->fullCommand($task),
                'description' => $task->description,
                'expression' => $task->expression,
                'timezone' => $task->timezone instanceof \DateTimeZone ? $task->timezone->getName() : $task->timezone,
                'without_overlapping' => $task->withoutOverlapping,
                'on_one_server' => $task->onOneServer,
                'run_in_background' => $task->runInBackground,
                'even_in_maintenance_mode' => $task->evenInMaintenanceMode,
                'repeat_seconds' => $task->repeatSeconds,
                // Null for a skipped run: the task's own body never
                // executed, so there's no server/memory footprint of its
                // own to report — just the scheduler process's baseline.
                'server' => $status !== 'skipped' ? (gethostname() ?: null) : null,
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
     * Short, stable grouping identity for the task — used as `key`, so it
     * must stay put across runs regardless of the machine `command` was
     * normalized on. Deliberately not `fullCommand()`: an install upgrading
     * onto this recorder must keep matching its own already-recorded rows
     * for the same task, and PhpExecutableFinder's answer (see
     * `fullCommand()`) can differ from one scheduler run to the next even on
     * the same box (a cron entry invoking a different php-cli than a
     * `schedule:work` daemon does, say).
     */
    protected function name(ScheduledEvent $task): string
    {
        $command = $task->command ?? '';

        // Strip the php binary and artisan path for readability.
        if (str_contains($command, 'artisan')) {
            $command = trim(Str::after($command, 'artisan'), " '\"");
        }

        return $command !== '' ? $command : ($task->description ?: 'closure');
    }

    /**
     * The task's command exactly as `schedule:list` prints it — e.g.
     * "php artisan app:sync-chain-data" — or null for a closure, which has
     * no command string to normalize. Illuminate\Console\Scheduling\Event's
     * own formatter, so the dashboard reads the same thing that command
     * generates, rather than re-deriving a `php artisan` prefix by hand.
     *
     * Normalizing here, inside the CLI process that just ran the task,
     * rather than later when the dashboard renders it, is what keeps this
     * accurate: normalizeCommand() swaps in whatever
     * Illuminate\Support\php_binary() resolves to *right now*, and that can
     * differ under the web server process rendering the dashboard from what
     * it was under this CLI process — normalizing on the spot means the
     * stored string is already correct and the dashboard just displays it.
     */
    protected function fullCommand(ScheduledEvent $task): ?string
    {
        return $task->command !== null ? ScheduledEvent::normalizeCommand($task->command) : null;
    }
}
