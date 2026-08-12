<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

class Commands extends Recorder
{
    /**
     * A console process runs one command at a time, so a single scalar is
     * enough to bridge CommandStarting to its matching CommandFinished —
     * same reasoning as CacheInteractions' $startedAt.
     */
    protected ?float $startedAt = null;

    /**
     * The command line as actually invoked ("app:sync-data --day=3"), or
     * null when it carries nothing the entry's `key` doesn't already say.
     * Captured on CommandStarting because that's the only event carrying
     * the input — same single-scalar reasoning as $startedAt above.
     */
    protected ?string $commandLine = null;

    /**
     * Commands that are console plumbing rather than application work.
     * `schedule:finish` is the bookkeeping subprocess Laravel appends after
     * every background scheduled task (`... ; php artisan schedule:finish
     * <mutex> "$?"` — see Console\Scheduling\CommandBuilder), and it
     * inherits the task's run id from Context exactly like the real command
     * does. Recording it put a second, overlapping COMMAND bar on every
     * background task's timeline and floated an entry nobody scheduled to
     * the top of the Commands list, once per task run.
     */
    protected const IGNORED = ['schedule:finish'];

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, [$this, 'recordStarting']);
        $events->listen(CommandFinished::class, [$this, 'recordFinished']);
    }

    public function recordStarting(CommandStarting $event): void
    {
        if ($this->isIgnored($event->command)) {
            return;
        }

        $this->startedAt = microtime(true);
        $this->commandLine = $this->commandLine($event->input, $event->command);

        // A command-based scheduled task (Schedule::command()) always runs
        // this command in a *separate* `php artisan` subprocess, even when
        // scheduled to run "in the foreground" — see
        // Illuminate\Console\Scheduling\Event::execute(). The scheduled
        // task's own id rides across that process boundary via Laravel's
        // own Context dehydration/hydration (see
        // Monitor::beginScheduledTaskRun()), so it's already available here,
        // in the fresh subprocess, before any application code has run.
        // Stamped onto this run's own entry purely as a cross-reference
        // (see Monitor::beginCommandRun()'s $scheduledTaskRunId param) — this
        // run still gets its own fresh id and its own timeline, since
        // everything it triggers happened after the scheduler already
        // finished dispatching it.
        $scheduledTaskRunId = $this->monitor->inheritedScheduledTaskRunId();

        // Before the command's own handle() runs, so everything it triggers
        // (queries, mail, notifications, dispatched jobs) correlates onto
        // this run's own timeline — mirrors beginRequest()/beginJobAttempt().
        $this->monitor->beginCommandRun($event->command, $scheduledTaskRunId);
    }

    public function recordFinished(CommandFinished $event): void
    {
        if ($this->isIgnored($event->command) || $this->startedAt === null) {
            return;
        }

        $duration = round((microtime(true) - $this->startedAt) * 1000, 2);
        $this->startedAt = null;

        $commandLine = $this->commandLine;
        $this->commandLine = null;

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
            payload: array_filter([
                'exit_code' => $event->exitCode,
                // Deliberately *not* folded into `key`: the Commands list
                // and every stat on it group by key, so one row per
                // argument combination would fragment a single command's
                // history into an unusable pile of near-duplicates.
                'command' => $commandLine,
                'model_count' => $this->monitor->modelCount(),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
            ], fn ($value) => $value !== null),
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

    /**
     * Monitor's own housekeeping commands shouldn't show up as recorded
     * commands themselves, and neither should console plumbing (see
     * self::IGNORED).
     */
    protected function isIgnored(?string $command): bool
    {
        if ($command === null) {
            return true;
        }

        return str_starts_with($command, 'monitor:') || in_array($command, self::IGNORED, true);
    }

    /**
     * The command line as invoked, arguments and options included.
     * CommandStarting::$command only ever carries the bare name, so two runs
     * of the same command with different arguments ("--day=1" vs "--day=3")
     * are otherwise indistinguishable in the dashboard. Null when the input
     * adds nothing over the name, so the payload key stays absent rather
     * than duplicating `key`.
     *
     * A real `php artisan` run hands us an ArgvInput, whose raw tokens are
     * read directly rather than through __toString(): that escapes every
     * token not matching /^[\w-]+$/ through escapeshellarg(), which turns
     * any namespaced command name into "'app:sync-data' --day=3". Anything
     * else (Artisan::call()'s ArrayInput, a custom InputInterface) has no
     * raw tokens to read, so it falls back to the escaped rendering.
     */
    protected function commandLine(InputInterface $input, string $name): ?string
    {
        if ($input instanceof ArgvInput) {
            // getRawTokens() only exists on Symfony Console 7.2+; below that
            // the tokens are reachable only as a private property.
            $tokens = method_exists($input, 'getRawTokens')
                ? $input->getRawTokens()
                : (new ReflectionProperty(ArgvInput::class, 'tokens'))->getValue($input);

            $line = trim(implode(' ', $tokens));
        } else {
            $line = trim((string) $input);
        }

        return $line !== '' && $line !== $name ? $line : null;
    }
}
