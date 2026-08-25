<?php

namespace LaravelMonitor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\State\CommandState;
use LaravelMonitor\State\RequestState;
use LaravelMonitor\Support\Location;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Str as SupportStr;
use Throwable;

use function is_string;
use function json_decode;
use function ltrim;
use function trim;

class Monitor
{
    private float $timestamp;

    /** @var Entry[] */
    protected array $entries = [];

    protected bool $recording = true;

    /**
     * State of the HTTP request currently being recorded, or null outside a
     * request (console, queue workers): a shared per-request identity plus
     * a *live* lifecycle stage pointer
     * (`stage`) — not a set of independent timestamp markers — so every
     * entry recorded during the request can be tagged with the stage it
     * actually happened in (see `record()`), instead of being classified
     * after the fact by comparing its timestamp against stored phase
     * intervals (see `Support\Timeline::containingPhase()`, still used only
     * as a fallback for rows stored before this stage tag existed).
     */
    protected ?RequestState $request = null;

    /** The buffered root `request` entry, finalised (phases, duration) on flush. */
    protected ?Entry $pendingRequest = null;

    /**
     * Stack of queued job attempts currently being processed, outermost
     * first — same shape/purpose as $request but for jobs, so everything a
     * job's handle() triggers (queries, mail, notifications) can be
     * correlated onto a job attempt timeline the same way request children
     * are. A worker process handles jobs from the queue one at a time and
     * never concurrently with an HTTP request, so $request and this stack
     * are never both non-empty — but a job's own handle() can still dispatch
     * another job synchronously (e.g. dispatchSync(), or any queue
     * connection like 'sync' that fires JobProcessing/JobProcessed
     * in-process instead of through a separate worker), nesting a second
     * attempt inside the first. A stack (rather than a single nullable
     * frame) keeps the outer attempt's id/start/models intact while the
     * inner one is active, so entries recorded after the inner attempt ends
     * still correlate back onto the outer one instead of losing correlation
     * entirely.
     *
     * @var list<array{id: string, start: float, models: int}>
     */
    protected array $jobStack = [];

    /**
     * State of the artisan command currently running, or null outside one —
     * same shape/purpose as $job but for console commands, so anything a
     * command triggers (queries, mail, notifications, jobs it dispatches)
     * correlates onto its own timeline the same way. A console process runs
     * one command at a time, never concurrently with a request or a queued
     * job attempt, so at most one of $request/$job/$command is ever set.
     *
     */
    protected ?CommandState $command = null;

    /** The buffered root `command` entry, finalised (phases, duration) on flush — mirrors $pendingRequest. */
    protected ?Entry $pendingCommand = null;

    /**
     * State of the scheduled task currently running, or null outside one —
     * same shape/purpose as $command, but for `Schedule::call()`/closure
     * tasks executing directly in the scheduler's own process. Command-based
     * tasks (`Schedule::command()`) always run as a *separate* `php artisan`
     * subprocess even when "foreground" (see
     * Illuminate\Console\Scheduling\Event::execute()) — that subprocess mints
     * its own, independent `command` entry (own id, own timeline) rather than
     * sharing this one, and only references this run's id in its payload
     * (see beginScheduledTaskRun(), Recorders\Commands::recordStarting()):
     * everything the subprocess triggers happened well after the scheduler
     * itself finished dispatching it, so it belongs on the command's own
     * timeline, not this one.
     *
     * @var array{id: string, start: float, models: int}|null
     */
    protected ?array $scheduledTask = null;

    /**
     * Context key a scheduled task's own id rides under across the process
     * boundary a command-based task's own `php artisan` subprocess creates,
     * so that subprocess's own `command` entry can reference which task
     * dispatched it. Laravel's scheduler already dehydrates the whole
     * Context into that subprocess's `__LARAVEL_CONTEXT` env var and
     * rehydrates it there before any application code runs (see
     * Illuminate\Log\Context\ContextServiceProvider) — riding on that
     * existing, framework-native mechanism instead of a bespoke env var means
     * no changes are needed to how the subprocess is spawned at all.
     */
    protected const SCHEDULED_TASK_CONTEXT_KEY = 'monitor_scheduled_task_id';

    public function __construct(
        public Application $app,
        public Location $location,
    ) {
    }

    public function timestamp(float $timestamp): float
    {
        return $this->timestamp ??= $timestamp;
    }

    public function laravelVersion(): ?string
    {
        try {
            return $this->app->version();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The currently authenticated application user's id, prefixed with
     * whichever guard it came from ("{guard}:{id}") — two different guards
     * can independently hand out the same id to two entirely different
     * users, so a bare id would conflate them wherever entries get grouped
     * or filtered by user. Checks the default guard first, then every
     * other configured guard, skipping the dashboard's own guard (its own
     * logins aren't activity of the application being monitored). Returns
     * null when none of them has a user.
     */
    public function currentUserId(): int|string|null
    {
        foreach ($this->authGuardsToCheck() as $guard) {
            try {
                $user = Auth::guard($guard)->user();
            } catch (Throwable) {
                continue;
            }

            if ($user !== null) {
                return "{$guard}:{$user->getAuthIdentifier()}";
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function authGuardsToCheck(): array
    {
        $ordered = array_unique(array_filter([
            config('auth.defaults.guard'),
            ...array_keys(config('auth.guards', [])),
        ]));

        return array_values(array_diff($ordered, [MonitorUser::guardName()]));
    }

    /**
     * Buffer a new entry. It is persisted on flush (end of request/job) or
     * as soon as the buffer limit is reached.
     */
    public function record(
        RecordType $type,
        ?string $key = null,
        array $payload = [],
        ?float $duration = null,
        ?string $subtype = null,
        int|string|null $userId = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        // Live-tag the entry with the request's (or command's) current stage
        // — the fix for queries that run while a middleware is unwinding
        // after `$next()` (e.g. session persistence) getting swept into
        // whatever phase happened to be open the longest, purely because
        // their stored start_offset fell inside that phase's interval.
        if ($type !== RecordType::Request && $this->request !== null) {
            $payload['phase'] = $this->request->stage->value;
        } elseif ($type !== RecordType::Command && $this->command !== null) {
            $payload['phase'] = $this->command->stage->value;
        }

        $entry = new Entry(
            $type->value,
            $key,
            $payload,
            $duration,
            $subtype,
            $userId,
            // currentJob() before request: a job processed synchronously
            // inside the same process as the request that dispatched it
            // (e.g. a route that calls Artisan::call('queue:work') itself,
            // rather than a separate worker process) still has $this->request
            // set the whole time — without this, every entry the job's own
            // handle() produces (its own 'processed'/'failed' entry included)
            // would correlate onto the outer request instead of this specific
            // attempt, so findByRequestId($attemptId, 'job') could never find
            // it again by its own id, and its 'processed' entry would show up
            // as a stray extra "Queued Job" row among the request's own
            // children (see Support\Timeline::EVENT_TYPES, which doesn't
            // distinguish a job entry's subtype). A real, separate queue
            // worker process never has $this->request set at all, so this
            // reordering changes nothing for that (overwhelmingly more
            // common) case.
            //
            // scheduledTask before command: a command-based scheduled task's
            // own subprocess starts life already inside the outer
            // schedule:run command's own $command context (its
            // CommandStarting fired first) — a fresh $scheduledTask, begun
            // right before this specific task ran, must win so everything
            // it triggers correlates onto *this* task's run, not onto
            // schedule:run itself.
            $this->currentJob()['id'] ?? $this->request?->id ?? $this->scheduledTask['id'] ?? $this->command?->id ?? null,
            $this->startOffsetFor($type, $duration),
        );

        if ($type === RecordType::Request && $this->request !== null) {
            $this->pendingRequest = $entry;
        }

        if ($type === RecordType::Command && $this->command !== null) {
            $this->pendingCommand = $entry;
        }

        $this->entries[] = $entry;

        // `php artisan tinker` forks the whole PsySH REPL loop into a child
        // process (Psy\ExecutionLoop\ProcessForker), so every query/job/mail/
        // notification/... a user triggers interactively runs there — then
        // the child reports back over a socket and SIGKILLs itself. SIGKILL
        // skips PHP shutdown functions entirely, so CommandFinished (and the
        // flush() Commands::recordFinished() triggers from it) only ever
        // fires back in the *parent*, which never executed any of that code
        // and has nothing of it buffered. Detecting the fork — the live pid
        // no longer matches the one recorded when the run began — and
        // flushing immediately closes that window: a doomed child persists
        // its own entries before it can be killed, instead of losing them.
        if (
            $this->command !== null
            && $this->command->pid !== null
            && function_exists('posix_getpid')
            && posix_getpid() !== $this->command->pid
        ) {
            $this->flush();
        } elseif (count($this->entries) >= (int) $this->app['config']->get('monitor.buffer', 200)) {
            $this->flush();
        }
    }

    /**
     * Where on the request timeline this entry started, in ms: an event's
     * start is "now minus how long it took". The root request itself starts
     * at zero. Measured via elapsedMsPrecise() (not elapsedMs()) so the
     * offset keeps microtime()'s own microsecond precision instead of being
     * floored to a whole millisecond before it ever reaches storage — see
     * elapsedMsPrecise()'s own docs for why that still means round(x, 3),
     * not leaving the raw subtraction unrounded.
     */
    protected function startOffsetFor(RecordType $type, ?float $duration): ?float
    {
        if ($this->request !== null) {
            if ($type === RecordType::Request) {
                return 0.0;
            }

            return max(0.0, $this->elapsedMsPrecise() - ($duration ?? 0));
        }

        if (($job = $this->currentJob()) !== null) {
            if ($type === RecordType::Job) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $job['start']) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        if ($this->scheduledTask !== null) {
            if ($type === RecordType::ScheduledTask) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->scheduledTask['start']) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        if ($this->command !== null) {
            if ($type === RecordType::Command) {
                return 0.0;
            }

            $elapsed = max(0.0, round((microtime(true) - $this->command->timestamp) * 1000, 3));

            return max(0.0, $elapsed - ($duration ?? 0));
        }

        return null;
    }

    public function enabled(): bool
    {
        return $this->recording && (bool) $this->app['config']->get('monitor.enabled', true);
    }

    /**
     * Whether the current request is Monitor's own dashboard — checked
     * directly by path, or via memo.path for a Livewire wire:poll/
     * component-interaction request (those POST to Livewire's own update
     * endpoint instead, carrying the originating page's URL in the
     * snapshot rather than the request path). $ignorePaths lets a recorder
     * fold in its own extra exclusions (e.g. Requests also excludes other
     * dev-tool dashboards like Telescope/Pulse/Horizon).
     */
    public function isSelfRequest(array $ignorePaths = []): bool
    {
        if (! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app['request'];

        $patterns = [
            ...$ignorePaths,
            trim((string) $this->app['config']->get('monitor.path', 'monitor'), '/').'*',
        ];

        if (SupportStr::matchesAny($request->path(), $patterns)) {
            return true;
        }

        if (! $request->hasHeader('X-Livewire')) {
            return false;
        }

        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $path = $snapshot['memo']['path'] ?? null;

            if (is_string($path) && SupportStr::matchesAny(ltrim($path, '/'), $patterns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start tracking the current HTTP request. Called from
     * MonitorServiceProvider's app->booted() callback, once the framework
     * has finished booting but before any middleware has run; offsets are
     * measured from PHP's request start so they line up with the recorded
     * request duration.
     *
     * LARAVEL_START (set on the first line of public/index.php) takes
     * priority over REQUEST_TIME_FLOAT (set by the SAPI before PHP even
     * starts executing the script), so duration doesn't include
     * webserver/PHP bootstrap time outside Laravel's own lifecycle.
     */
    public function beginRequest(): void
    {
        $this->request = new RequestState(
            timestamp: $this->timestamp ?? microtime(true),
            id: (string) Str::uuid(),
            stage: ExecutionStage::BeforeMiddleware,
        );

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase(ExecutionStage::Bootstrap, 0, $elapsed);
        $this->request->currentExecutionStageStartedAtMicrotime = $elapsed;
    }

    public function requestId(): ?string
    {
        return $this->request?->id;
    }

    /**
     * Starts tracking a queued job attempt. Called by the Jobs recorder on
     * JobProcessing, before the job's own handle() runs — everything it
     * triggers (queries, mail, notifications, cache) picks up this id via
     * record()'s request_id fallback, the same way an HTTP request's
     * children do.
     */
    public function beginJobAttempt(): void
    {
        $this->jobStack[] = [
            'id' => (string) Str::uuid(),
            'start' => microtime(true),
            'models' => 0,
            'attempts' => 1,
        ];
    }

    public function jobAttemptId(): ?string
    {
        return $this->currentJob()['id'] ?? null;
    }

    /**
     * Called by the Jobs recorder once the attempt's own `job` entry has
     * been recorded. Pops just the innermost attempt — if handle() dispatched
     * a nested job synchronously, this restores the outer attempt as current
     * rather than clearing job-tracking state entirely.
     */
    public function endJobAttempt(): void
    {
        array_pop($this->jobStack);
    }

    /** @return array{id: string, start: float, models: int}|null */
    protected function currentJob(): ?array
    {
        return $this->jobStack === [] ? null : $this->jobStack[array_key_last($this->jobStack)];
    }

    /**
     * Starts tracking an artisan command run. Called by the Commands
     * recorder on CommandStarting, before the command's own handle() runs —
     * everything it triggers (queries, mail, notifications, jobs) picks up
     * this id via record()'s request_id fallback, the same way an HTTP
     * request's or a job attempt's children do. Always mints its own fresh
     * id, even for a command-based scheduled task's own subprocess — see
     * $scheduledTaskRunId.
     *
     * @param  ?string  $scheduledTaskRunId  when this run is a command-based
     *                                 scheduled task's own subprocess, the
     *                                 dispatching task's own run id (see
     *                                 inheritedScheduledTaskRunId()) — stamped
     *                                 into this run's own entry (see
     *                                 finalizePendingCommand()) purely as a
     *                                 cross-reference the dashboard uses to
     *                                 link the two runs together. Everything
     *                                 this subprocess triggers still belongs
     *                                 on *its own* timeline: it all happened
     *                                 after the scheduler already finished
     *                                 dispatching it.
     */
    public function beginCommandRun(string $name, ?string $scheduledTaskRunId = null): void
    {
        $this->command = new CommandState(
            // LARAVEL_START (the artisan entry script sets this the same way
            // public/index.php does for requests) rather than "now" — so the
            // bootstrap phase below, and every child entry's start_offset,
            // account for the framework boot that already happened before
            // CommandStarting fired, the same way beginRequest() does.
            timestamp: $this->timestamp ?? microtime(true),
            id: (string) Str::uuid(),
            name: $name,
            stage: ExecutionStage::Action,
        );

        // The pid this run actually started under — see record()'s fork
        // detection, which this exists solely to support.
        $this->command->pid = function_exists('posix_getpid') ? posix_getpid() : null;
        $this->command->scheduledTaskRunId = $scheduledTaskRunId;

        $elapsed = $this->commandElapsedMsPrecise() ?? 0.0;

        $this->recordCommandPhase(ExecutionStage::Bootstrap, 0, $elapsed);
        $this->command->currentExecutionStageStartedAtMicrotime = $elapsed;
    }

    /**
     * Milliseconds elapsed since the running command's own process started
     * (LARAVEL_START), or null outside one. round(x, 3): microtime() and
     * $this->command->timestamp are both ~1.7-billion-magnitude Unix epoch
     * floats, so subtracting them is a textbook floating-point catastrophic
     * cancellation — the large integer part eats into a double's ~15-17
     * significant digits, so what should be an exact "918" can come out as
     * "918.00000762939453125". Rounding to 3 decimals (microtime()'s own
     * microsecond resolution — the true precision ceiling here) snaps the
     * result back to the value it actually represents; this is cleanup of
     * an arithmetic artifact, not a premature display-rounding shortcut.
     */
    public function commandElapsedMsPrecise(): ?float
    {
        if ($this->command === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->command->timestamp) * 1000, 3));
    }

    /** Append a lifecycle phase for the running command (offsets/durations in ms, microsecond precision). */
    public function recordCommandPhase(ExecutionStage $stage, int|float $start, int|float $duration): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->phases[] = [
            'name' => $stage->value,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /**
     * Marks the handle()-done → terminating boundary. Called by the Commands
     * recorder on CommandFinished, before the command's own entry is
     * recorded — mirrors markTerminating(), but a command only ever has the
     * one Action phase to close out first (no render/unwinding steps of its
     * own).
     */
    public function markCommandTerminating(): void
    {
        $this->transitionCommandStage(ExecutionStage::Terminating);
    }

    /**
     * Marks the terminating → end boundary — called from
     * MonitorServiceProvider's whenCommandLifecycleIsLongerThan hook, the
     * last thing the console kernel runs (after every terminating callback,
     * including the app's own `terminating()` ones) before the process
     * exits. Closes out whatever the Terminating phase's own duration
     * turns out to be once that trailing work is included.
     */
    public function markCommandEnd(): void
    {
        $this->transitionCommandStage(ExecutionStage::End);
    }

    /**
     * Move the running command to a new lifecycle stage, closing out the
     * phase it was previously in — the command-side counterpart of
     * transitionStage(), without the `$from` guard (nothing currently needs
     * it: markCommandTerminating()/markCommandEnd() are each only ever
     * called from one place, in a fixed order).
     */
    protected function transitionCommandStage(ExecutionStage $stage): void
    {
        if ($this->command === null || $this->command->stage === $stage) {
            return;
        }

        $now = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command->stage, $this->command->currentExecutionStageStartedAtMicrotime, $now - $this->command->currentExecutionStageStartedAtMicrotime);

        $this->command->stage = $stage;
        $this->command->currentExecutionStageStartedAtMicrotime = $now;
    }

    public function commandRunId(): ?string
    {
        return $this->command?->id;
    }

    /** The running command's name, e.g. "app:sync-data" — used to label queries recorded outside a request/job. */
    public function commandName(): ?string
    {
        return $this->command?->name;
    }

    /** Called by the Commands recorder once the run's own `command` entry has been recorded. */
    public function endCommandRun(): void
    {
        $this->command = null;
    }

    /**
     * Starts tracking a scheduled task run. Called by the ScheduledTasks
     * recorder on ScheduledTaskStarting, before the task itself runs —
     * everything a closure/`Schedule::call()` task triggers in-process picks
     * up this id via record()'s request_id fallback. Also stamped onto
     * Context so a command-based task's own subprocess can reference it —
     * see SCHEDULED_TASK_CONTEXT_KEY.
     */
    public function beginScheduledTaskRun(): void
    {
        $id = (string) Str::uuid();

        $this->scheduledTask = [
            'id' => $id,
            'start' => microtime(true),
            'models' => 0,
        ];

        // Context is Laravel 11+ only (this package supports 10-13) — on 10,
        // a command-based task's subprocess simply won't correlate back to
        // its parent scheduled_task entry (same as "no such correlation
        // exists" elsewhere), rather than fatal on a missing class.
        if (class_exists(Context::class)) {
            Context::add(self::SCHEDULED_TASK_CONTEXT_KEY, $id);
        }
    }

    public function scheduledTaskRunId(): ?string
    {
        return $this->scheduledTask['id'] ?? null;
    }

    /** Called by the ScheduledTasks recorder once the run's own `scheduled_task` entry has been recorded. */
    public function endScheduledTaskRun(): void
    {
        $this->scheduledTask = null;

        if (class_exists(Context::class)) {
            Context::forget(self::SCHEDULED_TASK_CONTEXT_KEY);
        }
    }

    /**
     * The scheduled-task-run id inherited from the parent scheduler process
     * via Laravel's own Context dehydration/hydration, or null when running
     * standalone (a manually-invoked command, or one running outside any
     * scheduled task). Read by the Commands recorder on CommandStarting so a
     * command-based task's own subprocess can stamp which scheduled task
     * dispatched it into its own `command` entry's payload (see
     * beginCommandRun()) — a reference for the dashboard to link the two
     * runs together, not an id this run adopts as its own: everything this
     * subprocess triggers happened after the scheduler already finished
     * dispatching it, so it belongs on *this* run's own timeline instead.
     */
    public function inheritedScheduledTaskRunId(): ?string
    {
        if (! class_exists(Context::class)) {
            return null;
        }

        $id = Context::get(self::SCHEDULED_TASK_CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Correlation id shared between a mail-channel notification's own entry
     * and the `mail` entry its send produces, set while that channel's send
     * is in flight (between `NotificationSending` and `NotificationSent`).
     * One scalar is enough — same reasoning as CacheInteractions'
     * `$startedAt`: Laravel dispatches a notification's channels
     * sequentially within a single request/job, never concurrently, so
     * nothing else can interleave and overwrite it mid-flight.
     */
    protected ?string $pendingNotificationCorrelationId = null;

    /**
     * Starts a new correlation window for a mail-channel notification about
     * to be sent, returning the id both the Notifications and Mail
     * recorders should stamp onto their respective entries.
     */
    public function beginNotificationDispatch(): string
    {
        return $this->pendingNotificationCorrelationId = (string) Str::uuid();
    }

    /** The in-flight correlation id, or null outside a mail-channel notification dispatch. */
    public function pendingNotificationCorrelationId(): ?string
    {
        return $this->pendingNotificationCorrelationId;
    }

    public function endNotificationDispatch(): void
    {
        $this->pendingNotificationCorrelationId = null;
    }

    /**
     * Count every query executed during the request, tracked independently
     * of the Queries recorder's slow/fast tagging.
     */
    public function incrementQueryCount(): void
    {
        if ($this->request !== null && $this->enabled()) {
            $this->request->queries++;
        }
    }

    public function queryCount(): int
    {
        return $this->request?->queries ?? 0;
    }

    /**
     * Count every Eloquent model hydrated during the current request, job
     * attempt or command run — mirrors incrementQueryCount(), but across
     * whichever context is active (a job/command has no bootstrap/
     * middleware phases of its own, so unlike queries it can't fall back to
     * "outside a request means uncounted").
     */
    public function incrementModelCount(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->request !== null) {
            $this->request->hydratedModels++;
        } elseif ($this->jobStack !== []) {
            $this->jobStack[array_key_last($this->jobStack)]['models']++;
        } elseif ($this->scheduledTask !== null) {
            $this->scheduledTask['models']++;
        } elseif ($this->command !== null) {
            $this->command->hydratedModels++;
        }
    }

    public function modelCount(): int
    {
        return $this->request?->hydratedModels ?? $this->currentJob()['models'] ?? $this->scheduledTask['models'] ?? $this->command?->hydratedModels ?? 0;
    }

    /** Milliseconds elapsed since the request started, or null outside one. */
    public function elapsedMs(): ?int
    {
        if ($this->request === null) {
            return null;
        }

        return max(0, (int) round((microtime(true) - $this->request->timestamp) * 1000));
    }

    /**
     * Milliseconds elapsed since the request started, to 3 decimal places
     * (microsecond precision). Used wherever a recorded `duration` or
     * `start_offset` is derived from wall-clock time, including lifecycle
     * phase boundaries (bootstrap/middleware/...) — a whole-millisecond
     * elapsedMs() would round phases shorter than 1ms (e.g. "sending") down
     * to a reported 0ms. The round(x, 3) here isn't a premature
     * display-rounding shortcut: microtime() and $this->request->timestamp
     * are both ~1.7-billion-magnitude Unix epoch floats, so subtracting
     * them is a textbook floating-point catastrophic cancellation — the
     * large integer part eats into a double's ~15-17 significant digits, so
     * what should be an exact "400" can come out as
     * "400.00009536743164062500". Rounding to 3 decimals (microtime()'s own
     * microsecond resolution — the true precision ceiling of this
     * subtraction) snaps it back to the value it actually represents,
     * before that noise reaches Support\Timeline or
     * View\Components\Requests\Timeline's own percentage math.
     */
    public function elapsedMsPrecise(): ?float
    {
        if ($this->request === null) {
            return null;
        }

        return max(0.0, round((microtime(true) - $this->request->timestamp) * 1000, 3));
    }

    /**
     * Marks the middleware → action (controller) boundary. Called from a
     * closure middleware attached directly onto the matched route at
     * RouteMatched time (see Hooks\ControllerStartHook), as the route's own
     * last middleware entry — i.e. after every other route middleware's
     * `handle()` has run, right before the controller.
     */
    public function markControllerStart(): void
    {
        $this->transitionStage(ExecutionStage::Action, from: ExecutionStage::BeforeMiddleware);
    }

    /**
     * Marks the action (controller) → render boundary. Called on
     * Illuminate\Routing\Events\PreparingResponse, dispatched by
     * Router::prepareResponse() while still deep inside the route's own
     * middleware pipeline (Route::run()'s Pipeline `then()` callback) —
     * before bubbling back out through any middleware's post-`$next()` code.
     */
    public function markRenderStart(): void
    {
        $this->transitionStage(ExecutionStage::Render, from: ExecutionStage::Action);
    }

    /**
     * Marks the render → unwinding boundary. Called on
     * Illuminate\Routing\Events\ResponsePrepared, dispatched immediately
     * after PreparingResponse, once the Response object is finalized — from
     * here on, only middleware's own post-`$next()` work remains (e.g.
     * StartSession::handleStatefulRequest() persisting the session), which
     * is genuinely "still middleware", not "still rendering".
     */
    public function markUnwinding(): void
    {
        $this->transitionStage(ExecutionStage::AfterMiddleware, from: ExecutionStage::Render);
    }

    /**
     * Marks the point every middleware (route and global alike) has finished
     * unwinding and the response is about to be sent. Called on
     * Illuminate\Foundation\Http\Events\RequestHandled, which fires only
     * after the *entire* middleware pipeline has returned — whatever stage
     * the request is still in gets closed out here, not just 'render' or
     * 'unwinding', so an early return/exception that skipped those still
     * gets a sensible boundary instead of an open-ended phase.
     */
    public function markResponseReady(): void
    {
        $this->transitionStage(ExecutionStage::Sending);
    }

    /** Marks the start of the terminating phase (response already sent). */
    public function markTerminating(): void
    {
        $this->transitionStage(ExecutionStage::Terminating);
    }

    /**
     * Marks the terminating → end boundary — called from
     * MonitorServiceProvider's whenRequestLifecycleIsLongerThan hook, the
     * very last thing the HTTP kernel runs (after every terminable
     * middleware and the app's own `terminating()` callbacks) before
     * control returns to the web server. Closes out whatever the
     * Terminating phase's own duration turns out to be once that trailing
     * work is included.
     */
    public function markEnd(): void
    {
        $this->transitionStage(ExecutionStage::End);
    }

    /**
     * Whether a request or command run currently owns its own end-of-
     * lifecycle flush (see MonitorServiceProvider's
     * whenRequestLifecycleIsLongerThan/whenCommandLifecycleIsLongerThan
     * registration, which marks End then flushes) — the generic
     * app->terminating() safety net (jobs and scheduled tasks flush
     * explicitly per-attempt/per-run and never reach this at all) must skip
     * flushing here when true, since the HTTP/console kernel always runs
     * app->terminating() *before* those lifecycle hooks: flushing here too
     * would persist the entry before End ever gets marked, making it dead
     * on arrival.
     */
    public function hasTrackedExecution(): bool
    {
        return $this->request !== null || $this->command !== null;
    }

    /**
     * Move to a new lifecycle stage, closing out the phase the request was
     * previously in. Replaces the old approach of recording a handful of
     * independent timestamp markers and matching every entry's stored
     * start_offset against them after the fact (still available as
     * Support\Timeline::containingPhase(), kept only as a fallback for rows
     * stored before this stage tag existed): that approach couldn't tell
     * "still rendering the view" apart from "a middleware doing its own
     * post-`$next()` work" — both looked identical (just elapsed time)
     * once measured from outside. Tagging entries with the *live* stage at
     * record() time (see `record()`) removes that ambiguity entirely.
     *
     * @param  ?ExecutionStage  $from  if given, the transition is ignored
     *                                 unless the request is currently in
     *                                 this stage — guards against a
     *                                 framework event firing out of order,
     *                                 more than once, or not at all.
     */
    protected function transitionStage(ExecutionStage $stage, ?ExecutionStage $from = null): void
    {
        if ($this->request === null || $this->request->stage === $stage) {
            return;
        }

        if ($from !== null && $this->request->stage !== $from) {
            return;
        }

        $now = $this->elapsedMsPrecise();

        $this->recordPhase($this->request->stage, $this->request->currentExecutionStageStartedAtMicrotime, $now - $this->request->currentExecutionStageStartedAtMicrotime);

        $this->request->stage = $stage;
        $this->request->currentExecutionStageStartedAtMicrotime = $now;
    }

    /** Append a lifecycle phase (offsets/durations in ms, microsecond precision). */
    public function recordPhase(ExecutionStage $stage, int|float $start, int|float $duration): void
    {
        if ($this->request === null) {
            return;
        }

        $this->request->phases[] = [
            'name' => $stage->value,
            'start' => max(0, $start),
            'duration' => max(0, $duration),
        ];
    }

    /**
     * Complete the buffered root `request` entry right before it is stored:
     * close out whichever stage the request was still in (mirroring every
     * other stage transition), attach the collected phases and extend the
     * duration to cover the full lifecycle, so the recorded request
     * duration is the sum of every execution stage including Terminating.
     */
    protected function finalizePendingRequest(): void
    {
        $entry = $this->pendingRequest;

        if ($entry === null || $this->request === null) {
            return;
        }

        $this->pendingRequest = null;

        $elapsed = $this->elapsedMsPrecise();

        $this->recordPhase($this->request->stage, $this->request->currentExecutionStageStartedAtMicrotime, $elapsed - $this->request->currentExecutionStageStartedAtMicrotime);

        $entry->payload['phases'] = $this->request->phases;
        $entry->payload['query_count'] = $this->request->queries;
        $entry->payload['model_count'] = $this->request->hydratedModels;
        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /**
     * Complete the buffered root `command` entry right before it is stored —
     * mirrors finalizePendingRequest(): closes out whichever stage the
     * command was still in (always 'terminating' in practice, since
     * Commands::recordFinished() calls markCommandTerminating() just before
     * recording this entry), attaches the collected phases and extends the
     * duration to cover the full lifecycle (bootstrap through terminating),
     * not just the action/handle() portion Commands.php itself measures.
     */
    protected function finalizePendingCommand(): void
    {
        $entry = $this->pendingCommand;

        if ($entry === null || $this->command === null) {
            return;
        }

        $this->pendingCommand = null;

        $elapsed = $this->commandElapsedMsPrecise();

        $this->recordCommandPhase($this->command->stage, $this->command->currentExecutionStageStartedAtMicrotime, $elapsed - $this->command->currentExecutionStageStartedAtMicrotime);

        $entry->payload['phases'] = $this->command->phases;
        // The run's real start (LARAVEL_START), stamped the same way
        // Recorders\Requests/Jobs stamp theirs — created_at is only stored
        // at second precision and marks the run's *end*, so reconstructing
        // "started at" from created_at - duration disagrees with this by up
        // to a whole second (see Support\Format::startedAt()).
        $entry->payload['started_at'] = $this->command->timestamp;

        // Only set for a command-based scheduled task's own subprocess (see
        // beginCommandRun()'s $scheduledTaskRunId param) — the same
        // correlation_id mechanism Recorders\Mail/Notifications use to pair
        // their own two entries (see Contracts\Storage::findByCorrelationId()),
        // reused here so CommandRunController can look up the dispatching
        // scheduled_task entry to link to, and ScheduleRunController the
        // reverse. Purely a cross-reference — this run's own timeline stays
        // entirely its own regardless (see startOffsetFor()).
        if ($this->command->scheduledTaskRunId !== null) {
            $entry->payload['correlation_id'] = $this->command->scheduledTaskRunId;
        }

        $entry->duration = max($entry->duration ?? 0.0, $elapsed ?? 0.0);
    }

    /**
     * Persist all buffered entries through the configured storage driver.
     * Recording is paused while flushing so the storage writes themselves
     * (e.g. database queries) are never captured.
     */
    public function flush(): void
    {
        $this->finalizePendingRequest();
        $this->finalizePendingCommand();

        if ($this->entries === []) {
            return;
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->recording = false;

        try {
            $this->storage()->store($entries);
        } catch (Throwable $e) {
            // Monitoring must never take the application down, but a storage
            // failure (e.g. schema drift after an update whose migration
            // wasn't run) should still be diagnosable instead of silently
            // dropping every entry — reported rather than swallowed outright.
            $this->reportStorageFailure($e);
        } finally {
            $this->recording = true;
        }
    }

    /**
     * Report a storage failure through the app's exception handler.
     * Recording is already paused by flush(), so this cannot recurse into
     * the Exceptions recorder.
     */
    protected function reportStorageFailure(Throwable $e): void
    {
        try {
            $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // The exception handler itself is unavailable; nothing more we can do.
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
