<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use LaravelMonitor\Support\Host;
use LaravelMonitor\Support\RecordType;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

class Commands extends Recorder
{
    /** One command runs at a time per process, so a scalar bridges Starting to Finished. */
    protected ?float $startedAt = null;

    /**
     * The command line as invoked ("app:sync-data --day=3"), or null when it
     * adds nothing over `key`. Only CommandStarting carries the input.
     */
    protected ?string $commandLine = null;

    /**
     * Console plumbing, not application work: `schedule:finish` is the
     * bookkeeping subprocess Laravel appends after every background
     * scheduled task.
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

        // A Schedule::command() task runs in its own artisan subprocess; its
        // run id arrives via Context and is stored only as a cross-reference.
        $scheduledTaskRunId = $this->monitor->inheritedScheduledTaskRunId();

        // Before handle(), so everything it triggers lands on this run's timeline.
        $this->monitor->beginCommandRun($event->command, $scheduledTaskRunId);
    }

    public function recordFinished(CommandFinished $event): void
    {
        if ($this->isIgnored($event->command) || $this->startedAt === null) {
            return;
        }

        // round(x, 3) strips float noise from subtracting two epoch timestamps.
        $duration = round((microtime(true) - $this->startedAt) * 1000, 3);
        $this->startedAt = null;

        $commandLine = $this->commandLine;
        $this->commandLine = null;

        // Closes the 'action' phase and opens 'terminating'; must precede
        // record() so the phases are complete when flush() finalizes the entry.
        $this->monitor->markCommandTerminating();

        $this->monitor->record(
            type: RecordType::Command,
            key: $event->command,
            payload: array_filter([
                'exit_code' => $event->exitCode,
                // Kept out of `key` so stats don't split per argument combination.
                'command' => $commandLine,
                'model_count' => $this->monitor->modelCount(),
                'server' => Host::name(),
                'peak_memory' => memory_get_peak_usage(true),
            ], fn ($value) => $value !== null),
            duration: $duration,
            subtype: $event->exitCode === 0 ? 'success' : 'failed',
        );

        // Not flushed here: Hooks\CommandLifecycleEndHook flushes once the
        // trailing End phase is known.
    }

    /** Skips the package's own monitor:* commands and console plumbing (self::IGNORED). */
    protected function isIgnored(?string $command): bool
    {
        if ($command === null) {
            return true;
        }

        return str_starts_with($command, 'monitor:') || in_array($command, self::IGNORED, true);
    }

    /**
     * The command line with arguments/options, or null when it equals the name.
     * ArgvInput's raw tokens are used because its __toString() shell-escapes
     * "app:sync-data"; other inputs fall back to that escaped rendering.
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
