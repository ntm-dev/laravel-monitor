<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\RequestDetail;
use LaravelMonitor\Support\Fingerprint;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Preferences;
use LaravelMonitor\Support\RecordType;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_entries_and_flushes_to_storage(): void
    {
        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 80, '2xx', 1);
        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 2);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'request',
            'key' => 'GET /users',
            'subtype' => '2xx',
            'user_id' => 1,
        ]);
    }

    public function test_stats_by_subtype_groups_in_a_single_query(): void
    {
        Monitor::record(RecordType::Request, 'GET /users', [], 100, '2xx');
        Monitor::record(RecordType::Request, 'GET /users', [], 300, '2xx');
        Monitor::record(RecordType::Request, 'GET /posts', [], 50, '4xx');
        Monitor::flush();

        $storage = app(Storage::class);
        $since = CarbonImmutable::now()->subHour();

        $bySubtype = $storage->statsBySubtype('request', $since);

        $this->assertCount(2, $bySubtype);
        $this->assertSame(2, $bySubtype->get('2xx')->count);
        $this->assertSame(200.0, $bySubtype->get('2xx')->avg_duration);
        $this->assertSame(1, $bySubtype->get('4xx')->count);
        $this->assertNull($bySubtype->get('5xx'));

        // Matches what separate stats() calls per subtype would have returned.
        $this->assertSame($storage->stats('request', $since, '2xx')->count, $bySubtype->get('2xx')->count);
        $this->assertSame($storage->stats('request', $since, '2xx')->avg_duration, $bySubtype->get('2xx')->avg_duration);
    }

    public function test_aggregates_by_key(): void
    {
        Monitor::record(RecordType::Request, 'GET /users', [], 100, '2xx');
        Monitor::record(RecordType::Request, 'GET /users', [], 300, '2xx');
        Monitor::record(RecordType::Request, 'GET /posts', [], 50, '2xx');
        Monitor::flush();

        $groups = app(Storage::class)->aggregateByKey('request', CarbonImmutable::now()->subHour());

        $this->assertCount(2, $groups);
        $this->assertSame('GET /users', $groups->first()->key);
        $this->assertSame(2, $groups->first()->count);
        $this->assertSame(200.0, $groups->first()->avg_duration);
        $this->assertSame(300.0, $groups->first()->max_duration);
    }

    public function test_stats_and_recent_and_purge(): void
    {
        Monitor::record(RecordType::Exception, 'RuntimeException', ['message' => 'boom']);
        Monitor::flush();

        $storage = app(Storage::class);
        $since = CarbonImmutable::now()->subHour();

        $this->assertSame(1, $storage->stats('exception', $since)->count);
        $this->assertSame('boom', $storage->recent('exception', $since)->first()->payload['message']);

        $storage->purge();
        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_query_recorder_captures_every_query_regardless_of_duration(): void
    {
        event(new QueryExecuted('select * from users', [], 250.0, DB::connection()));
        event(new QueryExecuted('select * from posts', [], 5.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 2);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'query',
            'key' => 'select * from users',
            'duration' => 250,
        ]);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'query',
            'key' => 'select * from posts',
            'duration' => 5,
        ]);
    }

    /**
     * Regression test for a path-joining bug in Support\Location: basePath
     * already carries a trailing separator, and joinPaths() unconditionally
     * added another one for every segment, doubling the slash between the
     * app root and "vendor" (".../app//vendor/..."). str_starts_with()
     * against a real, single-separator file path from debug_backtrace()
     * then never matched, so isVendorFile()/isInternalFile() treated every
     * vendor/framework file as application code — forQueryTrace() stopped
     * at the very first frame after the internal one (typically inside
     * Illuminate\Events\Dispatcher, the query listener's own caller)
     * instead of skipping past it to find the real caller.
     */
    public function test_query_trace_skips_vendor_and_framework_frames_to_find_the_real_caller(): void
    {
        $location = $this->app->make(\LaravelMonitor\Support\Location::class);

        $trace = [
            ['file' => base_path('vendor/ntm-dev/laravel-monitor/src/Recorders/Queries.php'), 'line' => 63],
            ['file' => base_path('vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php'), 'line' => 50],
            ['file' => base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 'line' => 800],
            ['file' => base_path('app/Repositories/BookingRepository.php'), 'line' => 95],
        ];

        [$file, $line] = $location->forQueryTrace($trace);

        $this->assertSame('app/Repositories/BookingRepository.php', $file);
        $this->assertSame(95, $line);
    }

    public function test_query_recorder_normalizes_in_clauses_and_bulk_inserts_into_one_group(): void
    {
        event(new QueryExecuted('select * from users where id in (?, ?, ?)', [], 10.0, DB::connection()));
        event(new QueryExecuted('select * from users where id in (?, ?, ?, ?, ?)', [], 20.0, DB::connection()));
        event(new QueryExecuted('insert into logs (a, b) values (?, ?), (?, ?), (?, ?)', [], 5.0, DB::connection()));

        Monitor::flush();

        $keys = DB::table('monitor_entries')->where('type', 'query')->pluck('key');

        $this->assertSame(
            ['insert into logs (a, b) VALUES (?, ?)', 'select * from users where id IN (?)'],
            $keys->unique()->sort()->values()->all(),
        );
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function sqlNormalizationProvider(): array
    {
        return [
            'single placeholder IN is left alone' => ['select * from t where id in (?)', 'select * from t where id in (?)'],
            'multi placeholder IN collapses' => ['select * from t where id in (?, ?, ?)', 'select * from t where id IN (?)'],
            'NOT IN collapses too' => ['select * from t where id not in (?, ?)', 'select * from t where id not IN (?)'],
            'IN with a subquery is untouched' => ['select * from t where id in (select id from u)', 'select * from t where id in (select id from u)'],
            'single row VALUES is left alone' => ['insert into t (a) values (?)', 'insert into t (a) values (?)'],
            'multi row VALUES collapses to one row' => ['insert into t (a, b) values (?, ?), (?, ?), (?, ?)', 'insert into t (a, b) VALUES (?, ?)'],
            'unrelated word containing in is untouched' => ['select * from domains where name = ?', 'select * from domains where name = ?'],
        ];
    }

    #[DataProvider('sqlNormalizationProvider')]
    public function test_sql_normalize_key(string $input, string $expected): void
    {
        $this->assertSame($expected, \LaravelMonitor\Support\Sql::normalizeKey($input));
    }

    public function test_query_recorder_ignores_its_own_storage_table(): void
    {
        event(new QueryExecuted('select * from monitor_entries', [], 1.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    /**
     * Not just the entries table — the dashboard also queries its own
     * monitor_users/monitor_issues/etc. tables on ordinary page loads (e.g.
     * looking up the authenticated actor on every request), and those
     * queries are just as much Monitor's own internal activity as the
     * entries-table writes, not the monitored application's.
     */
    #[DataProvider('ownTableProvider')]
    public function test_query_recorder_ignores_all_of_monitors_own_tables(string $table): void
    {
        event(new QueryExecuted("select * from {$table}", [], 1.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public static function ownTableProvider(): array
    {
        return [
            ['monitor_aggregates'],
            ['monitor_issues'],
            ['monitor_users'],
            ['monitor_invitations'],
            ['monitor_password_resets'],
            ['monitor_email_changes'],
            ['monitor_webauthn_credentials'],
            ['monitor_oauth_accounts'],
        ];
    }

    public function test_cache_recorder_captures_duration_between_the_before_and_after_events(): void
    {
        if (! class_exists(RetrievingKey::class)) {
            $this->markTestSkipped('Illuminate\Cache\Events\RetrievingKey was added in Laravel 11.15; unavailable on this Laravel version.');
        }

        event($this->cacheEvent(RetrievingKey::class, 'users:1'));
        usleep(2000);
        event($this->cacheEvent(CacheHit::class, 'users:1', 'value'));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'cache')->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->duration);
        $this->assertGreaterThan(0, $row->duration);
    }

    public function test_cache_recorder_records_null_duration_without_a_preceding_before_event(): void
    {
        event($this->cacheEvent(CacheMissed::class, 'users:2'));

        Monitor::flush();

        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'cache',
            'key' => 'users:2',
            'subtype' => 'miss',
            'duration' => null,
        ]);
    }

    public function test_cache_recorder_captures_store_name_and_ttl_on_write(): void
    {
        event($this->cacheEvent(\Illuminate\Cache\Events\KeyWritten::class, 'users:1', 'value', storeName: 'redis', seconds: 60));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'cache')->where('subtype', 'write')->first();

        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        // storeName only exists on this event from Laravel 11 onward (#49754).
        if (property_exists(\Illuminate\Cache\Events\KeyWritten::class, 'storeName')) {
            $this->assertSame('redis', $payload['store']);
        } else {
            $this->assertArrayNotHasKey('store', $payload);
        }

        $this->assertSame(60, $payload['ttl']);
    }

    public function test_cache_recorder_omits_store_and_ttl_when_not_provided(): void
    {
        event($this->cacheEvent(CacheHit::class, 'users:1', 'value'));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'cache')->where('subtype', 'hit')->first();

        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);
        $this->assertArrayNotHasKey('store', $payload);
        $this->assertArrayNotHasKey('ttl', $payload);
    }

    public function test_notification_recorder_measures_duration_and_stamps_a_correlation_id_for_mail_channel(): void
    {
        $notifiable = new class
        {
            public function getKey(): int
            {
                return 1;
            }
        };
        $notification = new class {};

        event(new \Illuminate\Notifications\Events\NotificationSending($notifiable, $notification, 'mail'));
        usleep(2000);
        event(new \Illuminate\Notifications\Events\NotificationSent($notifiable, $notification, 'mail'));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'notification')->first();

        $this->assertNotNull($row);
        $this->assertSame('mail', $row->subtype);
        $this->assertNotNull($row->duration);
        $this->assertGreaterThan(0, $row->duration);

        $payload = json_decode($row->payload, true);
        $this->assertNotEmpty($payload['correlation_id'] ?? null);
    }

    public function test_notification_recorder_does_not_stamp_a_correlation_id_for_non_mail_channels(): void
    {
        $notifiable = new class {};
        $notification = new class {};

        event(new \Illuminate\Notifications\Events\NotificationSending($notifiable, $notification, 'database'));
        event(new \Illuminate\Notifications\Events\NotificationSent($notifiable, $notification, 'database'));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'notification')->first();
        $payload = json_decode($row->payload, true);

        $this->assertArrayNotHasKey('correlation_id', $payload);
    }

    public function test_mail_recorder_ignores_monitors_own_transactional_mail(): void
    {
        // Team invitations, password resets, email-change verification —
        // Monitor's own dashboard account-management mail (LaravelMonitor\Mail\*)
        // isn't activity of the application being monitored, the same way
        // Recorders\Authentication excludes the dashboard's own guard.
        $message = $this->emailMessage('You have been invited', 'invitee@example.com');

        event(new \Illuminate\Mail\Events\MessageSending($message, ['__laravel_mailable' => \LaravelMonitor\Mail\TeamInvitationMail::class]));
        event(new \Illuminate\Mail\Events\MessageSent($this->sentMessage($message, 'invitee@example.com'), ['__laravel_mailable' => \LaravelMonitor\Mail\TeamInvitationMail::class]));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_mail_recorder_tags_direct_and_notification_triggered_sends_differently(): void
    {
        $direct = $this->emailMessage('Direct mail', 'a@b.com');

        event(new \Illuminate\Mail\Events\MessageSending($direct, ['__laravel_mailable' => 'App\\Mail\\InvoiceMail']));
        event(new \Illuminate\Mail\Events\MessageSent($this->sentMessage($direct, 'a@b.com'), ['__laravel_mailable' => 'App\\Mail\\InvoiceMail']));

        $viaNotification = $this->emailMessage('Notification mail', 'c@d.com');

        event(new \Illuminate\Mail\Events\MessageSending($viaNotification, ['__laravel_notification' => 'App\\Notifications\\Welcome']));
        event(new \Illuminate\Mail\Events\MessageSent($this->sentMessage($viaNotification, 'c@d.com'), ['__laravel_notification' => 'App\\Notifications\\Welcome']));

        Monitor::flush();

        // Grouped by mailable/notification class, not the subject — see
        // Recorders\Mail::record()'s $groupKey.
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'mail',
            'key' => 'App\\Mail\\InvoiceMail',
            'subtype' => 'direct',
        ]);

        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'mail',
            'key' => 'App\\Notifications\\Welcome',
            'subtype' => 'notification',
        ]);
    }

    public function test_notification_and_its_mail_send_share_the_same_correlation_id(): void
    {
        $notifiable = new class {};
        $notification = new class {};

        event(new \Illuminate\Notifications\Events\NotificationSending($notifiable, $notification, 'mail'));

        $email = $this->emailMessage('Welcome email', 'a@b.com');
        event(new \Illuminate\Mail\Events\MessageSending($email, ['__laravel_notification' => get_class($notification)]));
        event(new \Illuminate\Mail\Events\MessageSent($this->sentMessage($email, 'a@b.com'), ['__laravel_notification' => get_class($notification)]));

        event(new \Illuminate\Notifications\Events\NotificationSent($notifiable, $notification, 'mail'));

        Monitor::flush();

        $notificationPayload = json_decode(DB::table('monitor_entries')->where('type', 'notification')->first()->payload, true);
        $mailPayload = json_decode(DB::table('monitor_entries')->where('type', 'mail')->first()->payload, true);

        $this->assertNotEmpty($notificationPayload['correlation_id'] ?? null);
        $this->assertSame($notificationPayload['correlation_id'], $mailPayload['correlation_id']);
    }

    private function emailMessage(string $subject, string $to): \Symfony\Component\Mime\Email
    {
        $email = new \Symfony\Component\Mime\Email;
        $email->subject($subject)->to($to)->from('noreply@x.com')->text('hello');

        return $email;
    }

    /**
     * MessageSent's real (non-serialized) constructor argument is
     * Illuminate\Mail\SentMessage, which wraps a Symfony\Component\Mailer\
     * SentMessage — not the Symfony\Component\Mime\Email fed to
     * MessageSending. Building that wrapper is what these events actually
     * carry in production.
     */
    private function sentMessage(\Symfony\Component\Mime\Email $email, string $to): \Illuminate\Mail\SentMessage
    {
        $envelope = new \Symfony\Component\Mailer\Envelope(
            new \Symfony\Component\Mime\Address('noreply@x.com'),
            [new \Symfony\Component\Mime\Address($to)],
        );

        return new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, $envelope));
    }

    /**
     * Builds a cache event instance by name, tolerant of the constructor
     * signature differing across Laravel versions — Laravel 11 added a
     * leading $storeName parameter that Laravel 10 doesn't have.
     */
    private function cacheEvent(string $eventClass, string $key, mixed $value = null, ?string $storeName = null, mixed $seconds = null): object
    {
        $reflection = new ReflectionClass($eventClass);

        $arguments = array_map(
            fn ($parameter) => match ($parameter->getName()) {
                'storeName' => $storeName,
                'key' => $key,
                'value' => $value,
                'seconds' => $seconds,
                default => $parameter->getDefaultValue(),
            },
            $reflection->getConstructor()->getParameters(),
        );

        return $reflection->newInstanceArgs($arguments);
    }

    public function test_scheduled_task_recorder_captures_overlap_background_and_timezone_flags(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $task = $schedule->command('inspire')->withoutOverlapping()->runInBackground()->timezone('UTC');

        event(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 12.5));

        $row = DB::table('monitor_entries')->where('type', 'scheduled_task')->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);

        $this->assertTrue($payload['without_overlapping']);
        $this->assertTrue($payload['run_in_background']);
        $this->assertSame('UTC', $payload['timezone']);
    }

    public function test_schedule_list_marks_a_task_no_longer_in_the_current_schedule_as_inactive(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // Still registered in Schedule::events() for this process.
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $liveTask = $schedule->command('app:sync-data')->everyMinute();
        event(new \Illuminate\Console\Events\ScheduledTaskFinished($liveTask, 20.0));

        // Recorded once, but nothing currently registers this command any
        // more — renamed or removed from the app's own Schedule::events().
        Monitor::record(RecordType::ScheduledTask, 'app:removed-task', [
            'command' => 'php artisan app:removed-task',
            'expression' => '* * * * *',
        ], 20.0, 'finished');
        Monitor::flush();

        $tasks = Livewire::test(\LaravelMonitor\Livewire\Schedule::class)->viewData('tasks');

        $live = $tasks->firstWhere('key', 'app:sync-data');
        $removed = $tasks->firstWhere('key', 'app:removed-task');

        $this->assertTrue($live->isActive);
        $this->assertNotNull($live->next_run_at);

        $this->assertFalse($removed->isActive);
        $this->assertNull($removed->next_run_at);

        $this->get('/monitor/schedule')
            ->assertOk()
            ->assertSee('line-through', false);
    }

    public function test_schedule_list_splits_a_task_into_its_own_row_when_only_its_cadence_changed(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // everySecond()/everyFiveSeconds()/... reduce to the very same
        // `* * * * *` expression as everyMinute() — only repeatSeconds
        // differs (see ManagesFrequencies::repeatEvery()) — so this has to
        // split on that too, not just the expression string, to actually
        // exercise the scenario reported: switching --day=3 from every
        // minute to every second.
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $oldTask = $schedule->command('app:sync-data')->everyMinute();

        // Historical runs recorded under the *old* cadence.
        Monitor::record(RecordType::ScheduledTask, 'app:sync-data', [
            'command' => 'php artisan app:sync-data',
            'expression' => $oldTask->expression,
            'repeat_seconds' => $oldTask->repeatSeconds,
        ], 20.0, 'finished');
        Monitor::flush();

        // The code has since changed to run every second instead. Schedule
        // doesn't expose a way to *unregister* $oldTask, but that's fine
        // here: Livewire\Schedule::data() only cares about each key's own
        // *final* live cadence, and a command scheduled twice in the same
        // process resolves to whichever registration is last in
        // Schedule::events() — the same outcome an app that actually
        // replaced the old ->everyMinute() call with this one would produce.
        $newTask = $schedule->command('app:sync-data')->everySecond();

        // At least one run has already happened under the new cadence too
        // — a cadence with zero runs yet wouldn't have a row to find.
        Monitor::record(RecordType::ScheduledTask, 'app:sync-data', [
            'command' => 'php artisan app:sync-data',
            'expression' => $newTask->expression,
            'repeat_seconds' => $newTask->repeatSeconds,
        ], 20.0, 'finished');
        Monitor::flush();

        $tasks = Livewire::test(\LaravelMonitor\Livewire\Schedule::class)->viewData('tasks');
        $rows = $tasks->where('key', 'app:sync-data')->values();

        $this->assertCount(2, $rows);

        $old = $rows->first(fn ($task) => (string) $task->repeat_seconds === (string) $oldTask->repeatSeconds);
        $new = $rows->first(fn ($task) => (string) $task->repeat_seconds === (string) $newTask->repeatSeconds);

        $this->assertNotNull($old);
        $this->assertFalse($old->isActive);
        $this->assertNull($old->next_run_at);
        $this->assertSame(1, $old->finished);

        $this->assertNotNull($new);
        $this->assertTrue($new->isActive);
        $this->assertNotNull($new->next_run_at);
        $this->assertSame(1, $new->finished);
    }

    protected function commandEvents(string $command): array
    {
        return [
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        ];
    }

    /**
     * Hides the *scheduler's own* in-process scheduled-task frame from
     * $callback, restoring it afterwards — simulating the process boundary a
     * command-based task's own subprocess actually starts fresh across (its
     * Monitor instance never has $scheduledTask set at all — see
     * Monitor::inheritedScheduledTaskRunId()). A test that calls
     * beginScheduledTaskRun() and then fires CommandStarting/QueryExecuted/
     * CommandFinished in the same process, without this, leaves both
     * $scheduledTask and $command non-null at once for the command events —
     * an impossible state in production — under which record()'s request_id
     * fallback picks $scheduledTask['id'] ahead of $command['id'], masking
     * correlation_id bugs these tests exist to catch. Restored (rather than
     * left null) so a ScheduledTaskFinished fired after $callback — standing
     * in for control returning to the scheduler process — still records its
     * own `scheduled_task` entry under the right id.
     */
    protected function withoutScheduledTaskFrame(callable $callback): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);
        $property = new ReflectionProperty(\LaravelMonitor\Monitor::class, 'scheduledTask');

        $frame = $property->getValue($monitor);
        $property->setValue($monitor, null);

        try {
            $callback();
        } finally {
            $property->setValue($monitor, $frame);
        }
    }

    public function test_command_recorder_captures_exit_code_and_duration(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        usleep(1000);
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->assertNotNull($row);
        $this->assertSame('app:sync-data', $row->key);
        $this->assertSame('success', $row->subtype);
        $this->assertNotNull($row->duration);
        $this->assertGreaterThan(0, $row->duration);

        $payload = json_decode($row->payload, true);
        $this->assertSame(0, $payload['exit_code']);
    }

    public function test_command_recorder_tags_a_non_zero_exit_code_as_failed(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 1));
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->assertSame('failed', $row->subtype);
        $this->assertSame(1, json_decode($row->payload, true)['exit_code']);
    }

    public function test_command_recorder_captures_the_arguments_the_command_was_invoked_with(): void
    {
        $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'app:sync-data', '--day=3']);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'command')->first();

        // `key` stays the bare name — the Commands list groups by it, and one
        // row per argument combination would fragment a command's history.
        $this->assertSame('app:sync-data', $row->key);
        $this->assertSame('app:sync-data --day=3', json_decode($row->payload, true)['command']);
    }

    public function test_command_recorder_omits_the_invocation_when_it_adds_nothing_to_the_name(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->assertArrayNotHasKey('command', json_decode($row->payload, true));
    }

    public function test_command_recorder_records_the_run_start_so_it_survives_second_precision_timestamps(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'command')->first();
        $payload = json_decode($row->payload, true);

        // created_at is stored at second precision and marks the run's END,
        // so the run page and the runs list can only agree on "started at"
        // if the exact start was recorded (see Support\Format::startedAt()).
        $this->assertArrayHasKey('started_at', $payload);
        $this->assertEqualsWithDelta(microtime(true), $payload['started_at'], 60);
    }

    public function test_command_recorder_ignores_the_scheduler_own_finish_subprocess(): void
    {
        [$input, $output] = $this->commandEvents('schedule:finish');

        event(new \Illuminate\Console\Events\CommandStarting('schedule:finish', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('schedule:finish', $input, $output, 0));

        // Laravel appends this bookkeeping subprocess to every background
        // scheduled task, and it inherits the task's run id — recording it
        // put a second, overlapping COMMAND bar on the task's timeline.
        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_command_recorder_ignores_the_package_own_housekeeping_commands(): void
    {
        [$input, $output] = $this->commandEvents('monitor:aggregate');

        event(new \Illuminate\Console\Events\CommandStarting('monitor:aggregate', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('monitor:aggregate', $input, $output, 0));

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_command_recorder_correlates_queries_triggered_during_the_run(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new QueryExecuted('select * from users', [], 5.0, DB::connection()));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();
        $queryRow = DB::table('monitor_entries')->where('type', 'query')->first();

        $this->assertNotNull($commandRow->request_id);
        $this->assertSame($commandRow->request_id, $queryRow->request_id);
    }

    public function test_command_run_page_displays_its_correlated_query(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new QueryExecuted('select * from users', [], 5.0, DB::connection()));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            ->assertSeeText('app:sync-data')
            ->assertSeeText('QUERY');
    }

    public function test_command_run_page_links_to_its_scheduled_task_instead_of_charting_it(): void
    {
        if (! class_exists(\Illuminate\Support\Facades\Context::class)) {
            $this->markTestSkipped('Illuminate\Support\Facades\Context was added in Laravel 11; the correlation_id link this test checks is a documented no-op without it (see Monitor::inheritedScheduledTaskRunId()).');
        }

        Gate::define('viewMonitor', fn ($user = null) => true);

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $task = $schedule->command('inspire')->runInBackground();

        // Stands in for the scheduler process: the run id it mints is what a
        // command-based task's own `php artisan` subprocess references (as a
        // correlation_id, not as its own request_id — see
        // Monitor::inheritedScheduledTaskRunId()/beginCommandRun()).
        Monitor::beginScheduledTaskRun();
        $scheduledTaskRunId = app(\LaravelMonitor\Monitor::class)->scheduledTaskRunId();

        $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'app:sync-data', '--day=3']);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        $this->withoutScheduledTaskFrame(function () use ($input, $output) {
            event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
            event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        });

        event(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 0.02));

        // The command run's own id — a fresh one of its own, not the
        // scheduled task's, which is why the URL below can't just reuse
        // $scheduledTaskRunId the way a shared-timeline design would.
        $commandRunId = DB::table('monitor_entries')->where('type', 'command')->value('request_id');

        $response = $this->get('/monitor/commands/runs/'.$commandRunId)->assertOk();

        // The arguments the run was actually invoked with...
        $response->assertSeeText('app:sync-data --day=3');

        // ...and its dispatching task as a link, not as a bar drawn at
        // offset 0 against the scheduler process's own, unrelated clock.
        $response->assertSee(route('monitor.schedule.runs.show', $scheduledTaskRunId), false);
        $response->assertDontSee('SCHEDULED_TASK');
    }

    public function test_command_run_pages_query_is_positioned_on_the_commands_own_clock_not_the_schedulers(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $task = $schedule->command('app:sync-data')->runInBackground();

        Monitor::beginScheduledTaskRun();
        $scheduledTaskRunId = app(\LaravelMonitor\Monitor::class)->scheduledTaskRunId();

        $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'app:sync-data']);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        $this->withoutScheduledTaskFrame(function () use ($input, $output) {
            event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
            event(new QueryExecuted('select 1', [], 5.0, DB::connection()));
            event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        });

        event(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 0.02));

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();
        $duration = (float) $commandRow->duration;

        // The query belongs entirely to the command's own run — a fresh,
        // independent id and timeline (see Monitor::beginCommandRun()) — so
        // its offset is naturally measured against the command's own clock
        // with no rebase needed, and it never lands on the scheduled task's
        // own (much shorter) timeline at all.
        $tracks = $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            ->viewData('tracks');

        $queryEntry = collect($tracks[0]['entries'])->firstWhere('type', 'query');

        $this->assertNotNull($queryEntry);
        $this->assertLessThanOrEqual($duration, $queryEntry->start);

        $scheduleTracks = $this->get('/monitor/schedule/runs/'.$scheduledTaskRunId)
            ->assertOk()
            ->viewData('tracks');

        // Only the root bar itself — Timeline::build() always includes that
        // — no 'query' event nested under it the way a shared-timeline
        // design would.
        $this->assertNull(collect($scheduleTracks[0]['entries'])->firstWhere('type', 'query'));
    }

    public function test_schedule_run_page_links_to_the_command_it_dispatched(): void
    {
        if (! class_exists(\Illuminate\Support\Facades\Context::class)) {
            $this->markTestSkipped('Illuminate\Support\Facades\Context was added in Laravel 11; the correlation_id link this test checks is a documented no-op without it (see Monitor::inheritedScheduledTaskRunId()).');
        }

        Gate::define('viewMonitor', fn ($user = null) => true);

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $task = $schedule->command('app:sync-data')->runInBackground();

        Monitor::beginScheduledTaskRun();
        $scheduledTaskRunId = app(\LaravelMonitor\Monitor::class)->scheduledTaskRunId();

        $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'app:sync-data']);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        $this->withoutScheduledTaskFrame(function () use ($input, $output) {
            event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
            event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        });

        event(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 0.02));

        $commandRunId = DB::table('monitor_entries')->where('type', 'command')->value('request_id');

        $this->get('/monitor/schedule/runs/'.$scheduledTaskRunId)
            ->assertOk()
            ->assertSee(route('monitor.commands.runs.show', $commandRunId), false);
    }

    public function test_commands_list_reports_a_total_and_a_p95_alongside_the_success_failure_split(): void
    {
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $duration) {
            Monitor::record(RecordType::Command, 'app:sync-data', ['exit_code' => 0], $duration, 'success');
        }
        Monitor::record(RecordType::Command, 'app:sync-data', ['exit_code' => 1], 200, 'failed');
        Monitor::flush();

        $row = Livewire::test(\LaravelMonitor\Livewire\Commands::class)
            ->viewData('commands')
            ->firstWhere('key', 'app:sync-data');

        $this->assertSame(10, $row->success);
        $this->assertSame(1, $row->failed);
        $this->assertSame(11, $row->total);

        // p95 has to come from the actual duration values — the failed run's
        // 200ms is the slowest of the eleven, so it must be the one reported.
        $this->assertSame(200.0, $row->p95_duration);
        $this->assertGreaterThan($row->avg_duration, $row->p95_duration);
    }

    public function test_command_detail_runs_list_falls_back_to_the_bare_name_when_no_invocation_was_captured(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // A command invoked with no arguments (e.g. an interactive `tinker`
        // session, not `tinker --execute=...`) carries nothing extra to show —
        // Recorders\Commands::commandLine() deliberately omits `command` from
        // the payload rather than duplicating `key`. The runs list must still
        // show the bare name instead of leaving the cell blank.
        Monitor::record(RecordType::Command, 'tinker', ['exit_code' => 0], 50, 'success');
        Monitor::flush();

        $this->get('/monitor/commands/'.\LaravelMonitor\Support\KeyHash::for('tinker'))
            ->assertOk()
            // The run row's own command cell specifically (its class list is
            // unique on the page) — the page heading already shows the bare
            // name regardless of this fallback, so asserting "tinker" alone
            // would pass either way.
            ->assertSee('dark:text-neutral-400" title="tinker">tinker</td>', false);
    }

    public function test_command_run_page_truncates_a_long_invocation_instead_of_overflowing_the_card(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $longArgument = str_repeat('a', 300);
        $input = new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'app:sync-data', "--day={$longArgument}"]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();

        // The General card's `command` row must shrink/truncate like every
        // other value with no length limit on this page (breadcrumb, h1) —
        // not the fixed-width `shrink-0` every other row in that card uses,
        // which would push the card wider than its column instead of
        // eliding the text.
        $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            ->assertSee('min-w-0 shrink truncate font-mono text-xs text-neutral-800 dark:text-neutral-200" title="app:sync-data --day='.$longArgument.'"', false);
    }

    public function test_command_run_page_breadcrumb_links_back_to_that_command_own_runs(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            // Hashed by the command's *name*, which is what its runs list is
            // keyed by — not by this run's own invocation.
            ->assertSee(route('monitor.commands.show', ['hash' => \LaravelMonitor\Support\KeyHash::for('app:sync-data')]), false);
    }

    public function test_command_timeline_names_the_action_phase_handle(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));
        Monitor::flush();

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            // Both spellings: the tree/bar row renders the phase's *label*
            // (uppercased in CSS, so it stays "Handle" in the markup) while
            // the Alpine inspector's entry map carries the badge.
            ->assertSee('Handle')
            ->assertSee('HANDLE')
            ->assertDontSee('Action')
            ->assertDontSee('ACTION');
    }

    public function test_command_run_page_returns_404_for_unknown_run(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/commands/runs/does-not-exist')->assertNotFound();
    }

    public function test_recording_can_be_disabled(): void
    {
        config(['monitor.enabled' => false]);

        Monitor::record(RecordType::Request, 'GET /users');
        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_exception_recorder_fingerprints_and_classifies(): void
    {
        $exception = new RuntimeException('Charge declined for order 4821');

        // Reporting straight through the handler (rather than via the
        // report() helper) mimics an exception that crashed the request/job
        // outright instead of one an app deliberately caught and reported.
        app(ExceptionHandler::class)->report($exception);
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'exception')->first();

        $this->assertNotNull($row);
        $this->assertSame('unhandled', $row->subtype);
        $this->assertSame(32, strlen($row->key));

        $payload = json_decode($row->payload, true);
        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertFalse($payload['handled']);
        $this->assertNotEmpty($payload['frames']);
    }

    public function test_exception_recorder_attributes_the_top_frame_to_the_throw_site(): void
    {
        // getTrace()[0]'s file/line point to wherever the throwing method was
        // *called from*, not to the throw itself — pairing that location with
        // the throwing method's own name (as the buggy code briefly did)
        // mislabels the top frame, and can even flag an application method as
        // vendor code when its caller happens to live in vendor/.
        $thrower = new class
        {
            public function boom(): never
            {
                throw new RuntimeException('kaboom');
            }
        };

        try {
            $thrower->boom();
        } catch (RuntimeException $exception) {
        }

        app(ExceptionHandler::class)->report($exception);
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'exception')->first();
        $payload = json_decode($row->payload, true);
        $topFrame = $payload['frames'][0];

        $this->assertSame($exception->getLine(), $topFrame['line']);
        $this->assertStringContainsString('boom', $topFrame['label']);
        $this->assertFalse($topFrame['vendor']);
    }

    public function test_exception_recorder_marks_deliberately_reported_exceptions_as_handled(): void
    {
        $exception = new RuntimeException('Retrying webhook');

        // Going through the report() helper (rather than calling the handler
        // directly) is what marks this as a deliberate, non-crashing report.
        report($exception);
        Monitor::flush();

        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'exception',
            'subtype' => 'handled',
        ]);
    }

    public function test_fingerprint_groups_by_normalized_message(): void
    {
        $same = Fingerprint::for('App\\Boom', 'No results for model 41', 'app/X.php:10');
        $alsoSame = Fingerprint::for('App\\Boom', 'No results for model 992', 'app/X.php:10');
        $different = Fingerprint::for('App\\Boom', 'Totally different problem', 'app/X.php:10');

        $this->assertSame($same, $alsoSame);
        $this->assertNotSame($same, $different);
    }

    public function test_exception_groups_aggregate_handled_unhandled_and_users(): void
    {
        $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

        Monitor::record(RecordType::Exception, $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'unhandled', 1);
        Monitor::record(RecordType::Exception, $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'unhandled', 2);
        Monitor::record(RecordType::Exception, $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'handled', 2);
        Monitor::flush();

        $storage = app(Storage::class);
        $group = $storage->exceptionGroups(CarbonImmutable::now()->subHour())->firstWhere('key', $key);

        $this->assertNotNull($group);
        $this->assertSame(3, $group->count);
        $this->assertSame(2, $group->unhandled);
        $this->assertSame(1, $group->handled);
        $this->assertSame(2, $group->users);
        $this->assertSame('App\\Boom', $group->class);
        $this->assertNotNull($storage->firstSeen('exception', $key));
    }

    public function test_exception_detail_page_renders(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

        Monitor::record(RecordType::Exception, $key, [
            'class' => 'App\\Services\\Boom',
            'message' => 'Kaboom',
            'file' => 'app/X.php',
            'line' => 10,
            'frames' => [['file' => 'app/X.php', 'line' => 10, 'label' => 'App\\Services\\Boom->go', 'vendor' => false]],
        ], null, 'unhandled', 1);
        Monitor::flush();

        $this->get('/monitor/exceptions?key='.$key)
            ->assertOk()
            ->assertSee('Boom')
            ->assertSee('Copy as Markdown')
            ->assertSee('Occurrences');
    }

    public function test_exception_detail_page_links_to_the_request_it_happened_during(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $monitor = app(\LaravelMonitor\Monitor::class);
        $monitor->beginRequest();

        $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

        Monitor::record(RecordType::Request, 'GET /users', ['status' => 500], 20, '5xx');
        Monitor::record(RecordType::Exception, $key, [
            'class' => 'App\\Services\\Boom',
            'message' => 'Kaboom',
            'file' => 'app/X.php',
            'line' => 10,
            'frames' => [],
        ], null, 'unhandled', 1);

        $requestId = $monitor->requestId();

        Monitor::flush();

        $this->get('/monitor/exceptions?key='.$key)
            ->assertOk()
            ->assertSee('GET /users')
            ->assertSee(route('monitor.requests.show', $requestId), false);
    }

    public function test_exception_detail_page_links_to_the_command_run_it_happened_during(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $monitor = app(\LaravelMonitor\Monitor::class);
        $monitor->beginCommandRun('app:sync-data');

        $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

        Monitor::record(RecordType::Command, 'app:sync-data', ['exit_code' => 1], 20, 'failure');
        Monitor::record(RecordType::Exception, $key, [
            'class' => 'App\\Services\\Boom',
            'message' => 'Kaboom',
            'file' => 'app/X.php',
            'line' => 10,
            'frames' => [],
        ], null, 'unhandled', 1);

        $runId = $monitor->commandRunId();

        Monitor::flush();

        $this->get('/monitor/exceptions?key='.$key)
            ->assertOk()
            ->assertSee('app:sync-data')
            ->assertSee(route('monitor.commands.runs.show', $runId), false);
    }

    public function test_dashboard_is_protected_by_gate(): void
    {
        $this->get('/monitor')->assertForbidden();
    }

    public function test_dashboard_renders_when_authorized(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 120, '2xx');
        Monitor::flush();

        $this->get('/monitor')
            ->assertOk()
            ->assertSee('Monitor')
            ->assertSee('Requests');
    }

    public function test_every_tab_renders(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record(RecordType::Exception, 'RuntimeException', ['class' => 'RuntimeException', 'message' => 'boom', 'file' => 'app/X.php', 'line' => 1]);
        Monitor::record(RecordType::Query, 'select * from users', ['sql' => 'select * from users'], 250);
        Monitor::record(RecordType::Job, 'App\\Jobs\\SendEmail', ['queue' => 'default'], 40, 'processed');
        Monitor::record(RecordType::Command, 'app:sync-data', ['exit_code' => 0], 15, 'success');
        Monitor::record(RecordType::ScheduledTask, 'inspire', ['command' => 'inspire'], 12, 'finished');
        Monitor::record(RecordType::Cache, 'users:1', [], null, 'hit');
        Monitor::record(RecordType::OutgoingRequest, 'GET https://api.example.com', ['status' => 200], 90, 'success');
        Monitor::record(RecordType::Mail, 'Welcome', ['subject' => 'Welcome', 'to' => 'a@b.c']);
        Monitor::record(RecordType::Notification, 'App\\Notifications\\Invoice', ['channel' => 'mail'], null, 'mail');
        Monitor::record(RecordType::Log, 'Something happened', ['message' => 'Something happened', 'level' => 'warning'], null, 'warning');
        Monitor::record(RecordType::Auth, 'a@b.c', ['guard' => 'web'], null, 'login', 1);
        Monitor::flush();

        foreach (['overview', 'requests', 'exceptions', 'queries', 'jobs', 'commands', 'schedule', 'cache', 'outgoing', 'mail', 'notifications', 'users', 'logs'] as $tab) {
            $response = $this->get('/monitor/'.$tab)->assertOk();

            if (($dir = getenv('MONITOR_DUMP_HTML')) !== false) {
                file_put_contents($dir.'/'.$tab.'.html', $response->getContent());
            }
        }
    }

    public function test_requests_list_colors_methods_and_shows_error_icons(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 50, '2xx', 1);
        Monitor::record(RecordType::Request, 'POST /users', ['status' => 201], 60, '2xx', 1);
        Monitor::record(RecordType::Request, 'PUT /users/1', ['status' => 200], 70, '2xx', 1);
        Monitor::record(RecordType::Request, 'PATCH /users/1', ['status' => 200], 40, '2xx', 1);
        Monitor::record(RecordType::Request, 'DELETE /users/1', ['status' => 204], 30, '2xx', 1);
        Monitor::record(RecordType::Request, 'GET /orders', ['status' => 404], 20, '4xx', 1);
        Monitor::record(RecordType::Request, 'POST /payments', ['status' => 500], 90, '5xx', 1);
        Monitor::flush();

        $this->get('/monitor/requests')
            ->assertOk()
            ->assertSee('text-emerald-600', false)
            ->assertSee('text-blue-500', false)
            ->assertSee('text-rose-600', false)
            ->assertSee('fill-amber-500', false)
            ->assertSee('fill-rose-500', false);
    }

    public function test_request_detail_individual_requests_paginate_past_the_first_page(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 50, '2xx', 1);
        }
        Monitor::flush();

        $component = Livewire::test(RequestDetail::class, ['key' => 'GET /users']);

        $this->assertSame(60, $component->viewData('totalEntries'));
        $this->assertSame(1, $component->viewData('page'));
        $this->assertSame(2, $component->viewData('lastPage'));
        $this->assertCount(RequestDetail::PER_PAGE, $component->viewData('entries'));

        $component->call('nextPage');

        $this->assertSame(2, $component->viewData('page'));
        $this->assertCount(10, $component->viewData('entries'));
    }

    public function test_custom_range_from_the_picker_is_interpreted_in_the_viewers_timezone(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // A safely-past date (well before "today"), so Card::normalizeRange()'s
        // clamp-to-now never clips the window regardless of when this test runs.
        // 2026-01-15 04:00 UTC = 2026-01-15 11:00 in Asia/Ho_Chi_Minh (UTC+7).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 04:00:00', 'UTC'));
        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 50, '2xx', 1);
        Monitor::flush();
        CarbonImmutable::setTestNow();

        // A custom range picked as 09:00-13:00 *local* Vietnam time covers the
        // entry above (11:00 local). Parsed as bare UTC (the pre-fix bug),
        // that same window is 09:00-13:00 UTC — well after the 04:00 UTC
        // entry — so the route would wrongly disappear from the list.
        $this->withCookie(Preferences::COOKIE, json_encode(['timezone' => 'Asia/Ho_Chi_Minh']))
            ->get('/monitor/requests?'.http_build_query([
                'from' => '2026-01-15T09:00',
                'to' => '2026-01-15T13:00',
            ]))
            ->assertOk()
            ->assertSee('1 Route');
    }

    public function test_entries_recorded_during_a_request_are_correlated(): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        Monitor::record(RecordType::Query, 'select * from users', ['sql' => 'select * from users'], 25);
        $monitor->markResponseReady();
        Monitor::record(RecordType::Request, 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
        Monitor::flush();

        $storage = app(Storage::class);
        $requestId = $monitor->requestId();

        $this->assertNotNull($requestId);

        $root = $storage->findByRequestId($requestId);

        $this->assertNotNull($root);
        $this->assertSame('GET /users', $root->key);

        $phases = collect($root->payload['phases'] ?? [])->pluck('name');
        $this->assertContains('bootstrap', $phases);
        $this->assertContains('middleware', $phases);
        $this->assertContains('sending', $phases);

        $children = $storage->timelineFor($requestId);

        $this->assertCount(1, $children);
        $this->assertSame('query', $children->first()->type);
        $this->assertSame($requestId, $children->first()->request_id);
        $this->assertIsNumeric($children->first()->start_offset);
    }

    /**
     * Regression test for a query that runs from inside a middleware's own
     * post-`$next()` code (e.g. StartSession persisting the session after
     * the controller/view already produced the response) landing on the
     * "Render" bucket of the Request Detail timeline instead of a distinct
     * "after middleware" one. Monitor now tags every entry with the *live*
     * stage it was recorded in (see Monitor::record()/transitionStage()),
     * so this no longer depends on comparing a stored offset against a
     * stored phase interval after the fact.
     */
    public function test_query_recorded_while_unwinding_middleware_is_attributed_to_unwinding_not_render(): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        $monitor->markRenderStart();
        Monitor::record(RecordType::Query, 'select * from posts', ['sql' => 'select * from posts'], 5);
        $monitor->markUnwinding();
        Monitor::record(RecordType::Query, 'insert into sessions', ['sql' => 'insert into sessions'], 3);
        $monitor->markResponseReady();
        $monitor->markTerminating();
        Monitor::record(RecordType::Request, 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
        Monitor::flush();

        $storage = app(Storage::class);
        $requestId = $monitor->requestId();
        $root = $storage->findByRequestId($requestId);

        $phaseNames = collect($root->payload['phases'] ?? [])->pluck('name')->all();
        $this->assertSame(
            ['bootstrap', 'middleware', 'action', 'render', 'unwinding', 'sending', 'terminating'],
            $phaseNames,
        );

        $children = $storage->timelineFor($requestId)->keyBy('key');

        $this->assertSame('render', $children['select * from posts']->payload['phase']);
        $this->assertSame('unwinding', $children['insert into sessions']->payload['phase']);

        $entries = \LaravelMonitor\Support\Timeline::build($root, $children->values());
        $phasesById = collect($entries)->keyBy('type');

        $renderQuery = collect($entries)->first(fn ($entry) => ($entry->metadata['sql'] ?? null) === 'select * from posts');
        $unwindingQuery = collect($entries)->first(fn ($entry) => ($entry->metadata['sql'] ?? null) === 'insert into sessions');

        $this->assertSame($phasesById['render']->id, $renderQuery->parentId);
        $this->assertSame($phasesById['unwinding']->id, $unwindingQuery->parentId);
        $this->assertNotSame($renderQuery->parentId, $unwindingQuery->parentId);
    }

    /**
     * Rows stored before this "live" phase tag existed have no
     * `payload['phase']` key at all — Timeline must still attribute them to
     * *some* phase via the old start_offset/interval match, not blow up or
     * drop them from the timeline.
     */
    public function test_timeline_falls_back_to_offset_matching_when_no_phase_tag_is_stored(): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        Monitor::record(RecordType::Query, 'select * from legacy', ['sql' => 'select * from legacy'], 5);
        $monitor->markResponseReady();
        $monitor->markTerminating();
        Monitor::record(RecordType::Request, 'GET /legacy', ['method' => 'GET', 'path' => '/legacy', 'status' => 200], 60, '2xx', 1);
        Monitor::flush();

        $storage = app(Storage::class);
        $requestId = $monitor->requestId();
        $root = $storage->findByRequestId($requestId);
        $children = $storage->timelineFor($requestId);

        $legacyRow = $children->first();
        unset($legacyRow->payload['phase']);

        $entries = \LaravelMonitor\Support\Timeline::build($root, collect([$legacyRow]));
        $query = collect($entries)->first(fn ($entry) => $entry->type === 'query');

        $this->assertNotNull($query->parentId);
    }

    public function test_request_recorder_captures_correlated_timeline_end_to_end(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/demo-users', function () {
            Monitor::record(RecordType::Query, 'select * from users', ['sql' => 'select * from users'], 25);

            return 'ok';
        });

        $this->get('/demo-users')->assertOk();

        Monitor::flush();

        $row = \Illuminate\Support\Facades\DB::table('monitor_entries')->where('type', 'request')->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->request_id);

        $payload = json_decode($row->payload, true);

        $this->assertSame('GET', $payload['method']);
        $this->assertSame('/demo-users', $payload['path']);
        $this->assertArrayHasKey('peak_memory', $payload);
        $this->assertArrayHasKey('request_headers', $payload);
        $this->assertNotEmpty($payload['phases']);

        $query = \Illuminate\Support\Facades\DB::table('monitor_entries')->where('type', 'query')->first();

        $this->assertSame($row->request_id, $query->request_id);
    }

    public function test_request_recorder_captures_route_identity_and_redacted_body(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->post('/demo-login', function () {
            return 'ok';
        })->name('demo.login');

        $this->post('/demo-login', ['email' => 'a@b.com', 'password' => 'secret123'])->assertOk();

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->where('key', 'POST /demo-login')->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);

        $this->assertSame('demo.login', $payload['route_name']);
        $this->assertNotEmpty($payload['route_action']);
        $this->assertSame('a@b.com', $payload['body']['email']);
        $this->assertSame('••• redacted •••', $payload['body']['password']);
    }

    public function test_request_recorder_does_not_capture_a_body_for_get_requests(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/demo-search', function () {
            return 'ok';
        });

        $this->get('/demo-search?q=hello')->assertOk();

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->where('key', 'GET /demo-search')->first();

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('body', json_decode($row->payload, true));
    }

    public function test_request_detail_page_renders(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        Monitor::record(RecordType::Query, 'select * from users', ['sql' => 'select * from users'], 25);
        Monitor::record(RecordType::Request, 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
        Monitor::flush();

        $this->get('/monitor/requests/'.$monitor->requestId())
            ->assertOk()
            ->assertSee('/users')
            ->assertSee('Timeline');
    }

    public function test_request_detail_page_returns_404_for_unknown_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/requests/does-not-exist')->assertNotFound();
    }

    /**
     * A dispatching request and the job it queued always live in two
     * separate PHP processes in practice (the request itself, then whatever
     * later picks the job off the queue) — each with its own Monitor
     * instance, so the job's own row never actually shares the dispatching
     * request's request_id. Inserted directly here (rather than firing
     * real JobQueued/JobProcessed events in-process, which would incorrectly
     * nest under this same test's still-open beginRequest() context) to
     * reproduce that same two-request_id shape.
     *
     * @return array{0: string, 1: string} [$requestId, $jobRequestId]
     */
    protected function seedRequestThatDispatchedAJob(): array
    {
        $requestId = (string) Str::uuid();
        $jobRequestId = (string) Str::uuid();

        DB::table('monitor_entries')->insert([
            [
                'type' => 'request',
                'subtype' => '2xx',
                'key' => 'GET /users',
                'payload' => json_encode(['method' => 'GET', 'path' => '/users', 'status' => 200]),
                'duration' => 50,
                'request_id' => $requestId,
                'created_at' => now(),
            ],
            [
                'type' => 'job',
                'subtype' => 'queued',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123']),
                'duration' => null,
                'request_id' => $requestId,
                'created_at' => now(),
            ],
            [
                'type' => 'job',
                'subtype' => 'processed',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123', 'attempts' => 1]),
                'duration' => 30,
                'request_id' => $jobRequestId,
                'created_at' => now(),
            ],
        ]);

        return [$requestId, $jobRequestId];
    }

    /**
     * Regression test: once a dispatched job's 'queued' placeholder resolves
     * to an outcome, it used to be dropped entirely from the dispatching
     * request's own timeline (replaced solely by the separate job track
     * further down the page) — leaving no trace of *when*, within the
     * request's own lifecycle, it actually called dispatch(). See
     * MergesJobTimelines::buildTracks(): the resolved job still gets its own
     * track, but its dispatch-time row now stays inline too.
     */
    public function test_request_detail_page_still_shows_the_job_dispatch_row_once_the_job_resolves_to_an_outcome(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$requestId] = $this->seedRequestThatDispatchedAJob();

        $this->get(route('monitor.requests.show', $requestId))
            ->assertOk()
            ->assertSee('JOB DISPATCH');
    }

    /**
     * Visiting a dispatched job's own <request_url>/<job_id> link (built by
     * JobAttemptController::ancestorUrl() when that job's dispatcher is a
     * tracked request) still renders the request's own merged timeline, but
     * swaps the General info card for the job's own info and activates the
     * Jobs nav tab instead of Requests — see RequestDetailController.
     */
    public function test_request_detail_page_shows_the_jobs_own_info_and_activates_the_jobs_tab_when_visited_via_its_job_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$requestId, $jobRequestId] = $this->seedRequestThatDispatchedAJob();

        $this->get("/monitor/requests/{$requestId}/{$jobRequestId}")
            ->assertOk()
            ->assertViewHas('tab', 'jobs')
            ->assertSee('SendWelcomeEmail')
            ->assertSee('Timeline');
    }

    /**
     * A directly-visited job attempt whose dispatcher is a tracked request
     * redirects to that request's own page (JobAttemptController::ancestorUrl())
     * — in whichever url form the Requests tab itself would link to that
     * same instance (hashed by its route's key, see
     * monitor.requests.routes.request), with a trailing job id so the
     * landing page expands that job's own track.
     */
    public function test_job_attempt_page_redirects_to_its_dispatching_requests_hashed_url_with_a_trailing_job_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        [$requestId, $jobRequestId] = $this->seedRequestThatDispatchedAJob();

        $expectedUrl = route('monitor.requests.routes.request', ['hash' => KeyHash::for('GET /users'), 'requestId' => $requestId])."/{$jobRequestId}";

        $this->get("/monitor/jobs/attempts/{$jobRequestId}")->assertRedirect($expectedUrl);

        $this->get($expectedUrl)
            ->assertOk()
            ->assertViewHas('tab', 'jobs')
            ->assertSee('SendWelcomeEmail');
    }

    /**
     * Regression test: a job track's own "start" offset on the merged
     * request timeline must be measured from the dispatching request's
     * actual start, not from $root->created_at — Entry stamps created_at
     * when the row is recorded (RequestHandled), i.e. the request's own
     * END, not its start (see MergesJobTimelines::buildTracks()). A worker
     * that finishes processing before the dispatching request itself
     * completes (common — the worker is already polling when the job
     * lands) used to compute a negative offset here, clamped to 0ms, making
     * the job look dispatched at the exact same instant as the request
     * instead of partway through it.
     */
    public function test_job_track_start_offset_is_measured_from_the_requests_actual_start_not_its_created_at(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $requestId = (string) Str::uuid();
        $jobRequestId = (string) Str::uuid();

        // 1000ms request whose created_at (00:00:10) is stamped at the end
        // of its run — it actually started at 00:00:09.
        $requestCreatedAt = CarbonImmutable::parse('2024-01-01 00:00:10.000000');

        // Processed 400ms into the request's real run (00:00:09.400) and
        // took 100ms — finished well before the request itself did.
        $jobCreatedAt = CarbonImmutable::parse('2024-01-01 00:00:09.500000');

        // Formatted explicitly with microseconds rather than passed as
        // Carbon instances: the query builder stringifies a DateTimeInterface
        // binding via the grammar's own date format ('Y-m-d H:i:s', no
        // sub-second component — see Connection::prepareBindings()), which
        // would silently round both these timestamps down to the whole
        // second and defeat this test's own sub-second assertions below.
        DB::table('monitor_entries')->insert([
            [
                'type' => 'request',
                'subtype' => '2xx',
                'key' => 'GET /users',
                'payload' => json_encode(['method' => 'GET', 'path' => '/users', 'status' => 200]),
                'duration' => 1000,
                'request_id' => $requestId,
                'created_at' => $requestCreatedAt->format('Y-m-d H:i:s.u'),
            ],
            [
                'type' => 'job',
                'subtype' => 'queued',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123']),
                'duration' => null,
                'request_id' => $requestId,
                'created_at' => $requestCreatedAt->format('Y-m-d H:i:s.u'),
            ],
            [
                'type' => 'job',
                'subtype' => 'processed',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123', 'attempts' => 1]),
                'duration' => 100,
                'request_id' => $jobRequestId,
                'created_at' => $jobCreatedAt->format('Y-m-d H:i:s.u'),
            ],
        ]);

        // Job started processing at 09.500 - 0.100s = 09.400, i.e. 400ms
        // after the request's real 09.000 start — 40% of its 1000ms span.
        $this->get(route('monitor.requests.show', $requestId))
            ->assertOk()
            ->assertSee('margin-left: 40%', false);
    }

    /**
     * Regression test: Storage::jobExecutionsByJobId() must return a job's
     * retried outcomes oldest-first — MergesJobTimelines::jobTrack() numbers
     * "Attempt #N" purely by position in that collection (see its own
     * docblock), so an unordered query result (previously: no orderBy() at
     * all, leaving it to whatever the DB engine/index happened to return)
     * can hand a later retry a lower attempt number than an earlier one —
     * e.g. "Attempt #3" starting before "Attempt #2" even begins. Inserted
     * deliberately out of chronological order (the last outcome first) so
     * passing this test actually proves the query orders by created_at
     * rather than merely preserving insertion order by coincidence.
     */
    public function test_job_executions_by_job_id_returns_outcomes_oldest_first_regardless_of_insertion_order(): void
    {
        $jobId = 'job-retries-1';

        $third = ['created_at' => CarbonImmutable::parse('2024-01-01 00:00:07'), 'subtype' => 'processed', 'attempts' => 3];
        $first = ['created_at' => CarbonImmutable::parse('2024-01-01 00:00:05'), 'subtype' => 'released', 'attempts' => 1];
        $second = ['created_at' => CarbonImmutable::parse('2024-01-01 00:00:06'), 'subtype' => 'released', 'attempts' => 2];

        // Inserted last-outcome-first: a naive unordered query would return
        // rows in this same (wrong) order.
        foreach ([$third, $first, $second] as $outcome) {
            DB::table('monitor_entries')->insert([
                'type' => 'job',
                'subtype' => $outcome['subtype'],
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['job_id' => $jobId, 'attempts' => $outcome['attempts']]),
                'duration' => 10,
                'request_id' => (string) Str::uuid(),
                'created_at' => $outcome['created_at'],
            ]);
        }

        $executions = app(Storage::class)->jobExecutionsByJobId([$jobId], CarbonImmutable::parse('2024-01-01 00:00:00'));

        $orderedAttempts = $executions->get($jobId)->map(fn ($execution) => $execution->outcome->payload['attempts'])->values()->all();

        $this->assertSame([1, 2, 3], $orderedAttempts);
    }

    /**
     * Regression test: MergesJobTimelines::startedAt() must prefer a row's
     * own 'started_at' payload field (see Recorders\Requests/Jobs) over
     * reconstructing it from created_at - duration. Same 1000ms
     * request / 400ms-in / 100ms job shape (and the same expected 40%) as
     * {@see test_job_track_start_offset_is_measured_from_the_requests_actual_start_not_its_created_at()},
     * which exercises the created_at-minus-duration fallback — but here
     * created_at is deliberately set to something else entirely (it'd put
     * the job's processing start at/before the request's own created_at if
     * 'started_at' were ignored, clamping the offset to 0%) while
     * 'started_at' itself still carries the correct moment. 40% only comes
     * out if the explicit field wins over created_at/duration.
     */
    public function test_job_track_prefers_the_stored_started_at_payload_field_over_created_at_minus_duration(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $requestId = (string) Str::uuid();
        $jobRequestId = (string) Str::uuid();

        $requestStartedAt = CarbonImmutable::parse('2024-01-01 00:00:09.000000');
        $jobStartedAt = CarbonImmutable::parse('2024-01-01 00:00:09.400000');

        // Neither created_at below is consistent with its own
        // started_at/duration pairing above — reconstructing a start time
        // from them (the old fallback math) would land somewhere else
        // entirely, not at 00:00:09.000/00:00:09.400.
        $wrongRequestCreatedAt = CarbonImmutable::parse('2024-01-01 00:00:20.000000');
        $wrongJobCreatedAt = CarbonImmutable::parse('2024-01-01 00:00:21.000000');

        DB::table('monitor_entries')->insert([
            [
                'type' => 'request',
                'subtype' => '2xx',
                'key' => 'GET /users',
                'payload' => json_encode([
                    'method' => 'GET', 'path' => '/users', 'status' => 200,
                    'started_at' => (float) $requestStartedAt->format('U.u'),
                ]),
                'duration' => 1000,
                'request_id' => $requestId,
                'created_at' => $wrongRequestCreatedAt,
            ],
            [
                'type' => 'job',
                'subtype' => 'queued',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode(['connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123']),
                'duration' => null,
                'request_id' => $requestId,
                'created_at' => $requestStartedAt,
            ],
            [
                'type' => 'job',
                'subtype' => 'processed',
                'key' => 'App\\Jobs\\SendWelcomeEmail',
                'payload' => json_encode([
                    'connection' => 'database', 'queue' => 'default', 'job_id' => 'job-abc123', 'attempts' => 1,
                    'started_at' => (float) $jobStartedAt->format('U.u'),
                ]),
                'duration' => 100,
                'request_id' => $jobRequestId,
                'created_at' => $wrongJobCreatedAt,
            ],
        ]);

        // Same math as the created_at-fallback test: 400ms after the
        // request's real 00:00:09.000 start, over its own 1000ms span, is 40%.
        $this->get(route('monitor.requests.show', $requestId))
            ->assertOk()
            ->assertSee('margin-left: 40%', false);
    }

    public function test_hashed_route_resolves_to_the_requests_list_for_that_route(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Request, 'GET /users', ['status' => 200], 50, '2xx', 1);
        Monitor::flush();

        $this->get('/monitor/requests/routes/'.KeyHash::for('GET /users'))
            ->assertOk()
            ->assertSee('/users');
    }

    public function test_hashed_route_404s_for_an_unresolvable_hash(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/requests/routes/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_nested_hashed_route_renders_a_single_requests_detail_page(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        Monitor::record(RecordType::Request, 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
        Monitor::flush();

        $this->get('/monitor/requests/routes/'.KeyHash::for('GET /users').'/'.$monitor->requestId())
            ->assertOk()
            ->assertSee('/users')
            ->assertSee('Timeline');
    }

    public function test_notification_detail_page_links_to_its_correlated_mail_send(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $notifiable = new class
        {
            public function getKey(): int
            {
                return 1;
            }
        };
        $notification = new class {};

        event(new \Illuminate\Notifications\Events\NotificationSending($notifiable, $notification, 'mail'));

        $email = new \Symfony\Component\Mime\Email;
        $email->subject('Welcome email')->to('a@b.com')->from('noreply@x.com')->text('hi');
        $envelope = new \Symfony\Component\Mailer\Envelope(
            new \Symfony\Component\Mime\Address('noreply@x.com'),
            [new \Symfony\Component\Mime\Address('a@b.com')],
        );
        $sentMessage = new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, $envelope));
        event(new \Illuminate\Mail\Events\MessageSending($email, ['__laravel_notification' => get_class($notification)]));
        event(new \Illuminate\Mail\Events\MessageSent($sentMessage, ['__laravel_notification' => get_class($notification)]));

        event(new \Illuminate\Notifications\Events\NotificationSent($notifiable, $notification, 'mail'));

        Monitor::flush();

        $notificationId = DB::table('monitor_entries')->where('type', 'notification')->value('id');
        $mailId = DB::table('monitor_entries')->where('type', 'mail')->value('id');
        $notificationKey = DB::table('monitor_entries')->where('type', 'notification')->value('key');
        $mailKey = DB::table('monitor_entries')->where('type', 'mail')->value('key');

        $this->get('/monitor/notifications?key='.$notificationId)
            ->assertOk()
            ->assertSee('View sent email')
            ->assertSee(route('monitor.mail.sends.show', ['hash' => KeyHash::for($mailKey), 'id' => $mailId]), false);

        $this->get('/monitor/mail?key='.$mailId)
            ->assertOk()
            ->assertSee('Sent via notification')
            ->assertSee(route('monitor.notifications.sends.show', ['hash' => KeyHash::for($notificationKey), 'id' => $notificationId]), false);
    }

    public function test_notification_detail_page_shows_not_found_state_for_unknown_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/notifications?key=999999')
            ->assertOk()
            ->assertSee('could not be found');
    }

    public function test_notifications_list_groups_sends_by_class_and_class_detail_lists_each_one(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $key = 'App\\Notifications\\Welcome';
        Monitor::record(RecordType::Notification, $key, ['notification' => $key, 'channel' => 'mail'], 10, 'mail');
        Monitor::record(RecordType::Notification, $key, ['notification' => $key, 'channel' => 'mail'], 20, 'mail');
        Monitor::record(RecordType::Notification, $key, ['notification' => $key, 'channel' => 'database'], null, 'database');
        Monitor::flush();

        // The list groups all three sends into one row for the class...
        $this->get('/monitor/notifications')
            ->assertOk()
            ->assertSeeText('Welcome')
            ->assertSeeText('3');

        // ...and the class's own detail page lists each individual send.
        $this->get('/monitor/notifications?key='.urlencode($key))
            ->assertOk()
            ->assertSeeText('3 Sends');
    }

    public function test_mail_list_groups_sends_by_mailable_class_and_class_detail_lists_each_one(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $key = 'App\\Mail\\InvoiceMail';
        Monitor::record(RecordType::Mail, $key, ['subject' => 'Your invoice', 'to' => 'a@b.com', 'mailable' => $key], 5, 'direct');
        Monitor::record(RecordType::Mail, $key, ['subject' => 'Your invoice', 'to' => 'c@d.com', 'mailable' => $key], 8, 'direct');
        Monitor::flush();

        $this->get('/monitor/mail')
            ->assertOk()
            ->assertSeeText('InvoiceMail')
            ->assertSeeText('2');

        $this->get('/monitor/mail?key='.urlencode($key))
            ->assertOk()
            ->assertSeeText('2 Sends');
    }

    protected function syncJob(?string $jobId = null): \Illuminate\Queue\Jobs\SyncJob
    {
        $payload = json_encode([
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\\Jobs\\SendWelcomeEmail', 'command' => 'x'],
            'displayName' => 'App\\Jobs\\SendWelcomeEmail',
        ]);

        if ($jobId === null) {
            return new \Illuminate\Queue\Jobs\SyncJob(new \Illuminate\Container\Container, $payload, 'sync', 'default');
        }

        return new class(new \Illuminate\Container\Container, $payload, 'sync', 'default', $jobId) extends \Illuminate\Queue\Jobs\SyncJob
        {
            public function __construct($container, $job, $connectionName, $queue, protected string $fakeJobId)
            {
                parent::__construct($container, $job, $connectionName, $queue);
            }

            public function getJobId()
            {
                return $this->fakeJobId;
            }
        };
    }

    /**
     * Several Illuminate queue/cache events gained extra constructor
     * parameters in later Laravel versions than the package's declared
     * minimum (e.g. JobQueued's `queue`/`delay` post-10.0.0). The CI
     * matrix's prefer-lowest run resolves the early signature, and extra
     * positional args silently shift into the wrong slot instead of
     * erroring. Build the event via named args limited to whatever
     * parameters the installed version actually declares.
     */
    protected function constructEventCompatibly(string $class, array $namedArgs): object
    {
        $available = collect((new ReflectionClass($class))->getConstructor()->getParameters())->pluck('name');

        $args = collect($namedArgs)->only($available)->all();

        return new $class(...$args);
    }

    protected function jobQueuedEvent(string $connectionName, string $queue, string $id, $job, string $payload, ?int $delay = null): \Illuminate\Queue\Events\JobQueued
    {
        return $this->constructEventCompatibly(\Illuminate\Queue\Events\JobQueued::class, compact('connectionName', 'queue', 'id', 'job', 'payload', 'delay'));
    }

    protected function jobReleasedAfterExceptionEvent(string $connectionName, $job, ?int $backoff = null): \Illuminate\Queue\Events\JobReleasedAfterException
    {
        return $this->constructEventCompatibly(\Illuminate\Queue\Events\JobReleasedAfterException::class, compact('connectionName', 'job', 'backoff'));
    }

    public function test_job_recorder_correlates_queued_to_processed_via_job_id_and_captures_attempts(): void
    {
        $job = $this->syncJob('job-abc123');

        event($this->jobQueuedEvent('sync', 'default', 'job-abc123', $job, json_encode([])));
        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));
        event(new \Illuminate\Queue\Events\JobProcessed('sync', $job));

        Monitor::flush();

        $queuedPayload = json_decode(DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'queued')->value('payload'), true);
        $processedPayload = json_decode(DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'processed')->value('payload'), true);

        $this->assertSame('job-abc123', $queuedPayload['job_id']);
        $this->assertSame('job-abc123', $processedPayload['job_id']);
        $this->assertSame(1, $processedPayload['attempts']);
    }

    /**
     * Regression test: JobQueued::$job is the raw, as-dispatched job — for
     * Mail::queue()/Notification::send(..., queue), that's Laravel's own
     * Illuminate\Mail\SendQueuedMailable/SendQueuedNotifications wrapper,
     * not the Mailable/Notification itself. recordQueued() used to record
     * this wrapper's own class name via a plain get_class(), so every
     * queued mail showed up as an indistinguishable
     * "Illuminate\Mail\SendQueuedMailable" job — unlike the eventual
     * processed/failed/released entry for that same dispatch, which
     * already resolves to the wrapped class via Job::resolveName(). See
     * Recorders\Jobs::displayName().
     */
    public function test_job_recorder_resolves_a_wrapped_jobs_display_name_for_its_queued_entry(): void
    {
        // Stands in for Illuminate\Mail\SendQueuedMailable, which defines
        // displayName() to return the wrapped Mailable's own class instead
        // of its own.
        $wrapper = new class
        {
            public function displayName(): string
            {
                return 'App\\Mail\\WelcomeMail';
            }
        };

        event($this->jobQueuedEvent('database', 'default', 'job-abc123', $wrapper, json_encode([])));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'queued')->first();

        $this->assertNotNull($row);
        $this->assertSame('App\\Mail\\WelcomeMail', $row->key);
    }

    /**
     * Regression test: JobQueued::$queue is null whenever the job was
     * dispatched onto a connection's default queue rather than an explicit
     * one via onQueue() — Recorders\Jobs::resolveQueue() must then fall back
     * to that connection's own configured default queue name (config
     * queue.connections.<connection>.queue), not silently coerce to ''.
     */
    public function test_job_recorder_falls_back_to_the_connections_configured_default_queue(): void
    {
        config(['queue.connections.custom' => ['driver' => 'database', 'queue' => 'orders']]);

        $job = new class
        {
            public $queue = null;
        };

        event(new \Illuminate\Queue\Events\JobQueued('custom', null, 'job-abc123', $job, json_encode([]), null));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'queued')->first();

        $this->assertNotNull($row);
        $this->assertSame('orders', json_decode($row->payload, true)['queue']);
    }

    /**
     * Regression test: normalizeQueue() must recognize an sqs connection via
     * its actual config (queue.connections.<connection>.driver) and strip
     * that connection's configured prefix/suffix — an SQS queue's "name" is
     * really its full URL, unusable as a display value without this.
     */
    public function test_job_recorder_strips_the_sqs_connections_prefix_and_suffix_from_the_queue_name(): void
    {
        config(['queue.connections.sqs' => [
            'driver' => 'sqs',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789012',
            'suffix' => '.fifo',
        ]]);

        // JobQueued::$job is the raw, as-dispatched job (see displayName()'s
        // own docblock) — a real ShouldQueue job carries its queue via the
        // public Illuminate\Bus\Queueable::$queue, not the protected $queue
        // on the wrapped Illuminate\Queue\Jobs\Job that only later events
        // (JobProcessing, JobProcessed, ...) receive. This is also
        // resolveQueue()'s only source of the queue on Laravel versions
        // before JobQueued::$queue existed (pre-#55058).
        $job = new class
        {
            public $queue = 'orders';
        };

        event($this->jobQueuedEvent(
            'sqs',
            'https://sqs.us-east-1.amazonaws.com/123456789012/orders.fifo',
            'job-abc123',
            $job,
            json_encode([])
        ));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'queued')->first();

        $this->assertNotNull($row);
        $this->assertSame('orders', json_decode($row->payload, true)['queue']);
    }

    public function test_job_recorder_omits_job_id_for_sync_jobs_without_a_driver_assigned_id(): void
    {
        $job = $this->syncJob();

        event($this->jobQueuedEvent('sync', 'default', '', $job, json_encode([])));

        Monitor::flush();

        $payload = json_decode(DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'queued')->value('payload'), true);

        $this->assertArrayNotHasKey('job_id', $payload);
    }

    public function test_job_recorder_captures_released_status_distinct_from_failed(): void
    {
        $job = $this->syncJob();

        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));
        event($this->jobReleasedAfterExceptionEvent('sync', $job, 30));

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'released')->first();

        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);
        $this->assertSame(1, $payload['attempts']);

        // backoff only exists on this event from Laravel 12 onward (#58414).
        if (property_exists(\Illuminate\Queue\Events\JobReleasedAfterException::class, 'backoff')) {
            $this->assertSame(30, $payload['backoff']);
        } else {
            $this->assertArrayNotHasKey('backoff', $payload);
        }
    }

    /**
     * Regression test: a job's own handle() dispatching another job
     * synchronously (dispatchSync(), or any connection like 'sync' whose
     * queue fires JobProcessing/JobProcessed in-process) used to overwrite
     * Monitor's single job-tracking frame, losing the outer job's id/start
     * once the inner one finished — anything the outer job recorded
     * afterwards lost its correlation entirely. Monitor::$jobStack now nests
     * attempts instead.
     */
    public function test_nested_synchronous_job_dispatch_does_not_lose_the_outer_jobs_correlation(): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);
        $outer = $this->syncJob('outer-job-id');
        $inner = $this->syncJob('inner-job-id');

        event(new \Illuminate\Queue\Events\JobProcessing('sync', $outer));
        $outerAttemptId = $monitor->jobAttemptId();

        // Inner job dispatched synchronously from within the outer job's handle().
        event(new \Illuminate\Queue\Events\JobProcessing('sync', $inner));
        event(new \Illuminate\Queue\Events\JobProcessed('sync', $inner));

        // Still inside the outer job's handle(), after the nested dispatch returned.
        $this->assertSame($outerAttemptId, $monitor->jobAttemptId());
        Monitor::record(RecordType::Query, 'select * from users', ['sql' => 'select * from users'], 5);

        event(new \Illuminate\Queue\Events\JobProcessed('sync', $outer));

        Monitor::flush();

        $queryRow = DB::table('monitor_entries')->where('type', 'query')->first();
        $outerRow = DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'processed')->get()
            ->first(fn ($row) => json_decode($row->payload, true)['job_id'] === 'outer-job-id');

        $this->assertNotNull($outerRow);
        $this->assertNotNull($queryRow->request_id);
        $this->assertSame($outerRow->request_id, $queryRow->request_id);
    }

    public function test_job_recorder_captures_attempts_on_failure(): void
    {
        $job = $this->syncJob();

        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));
        event(new \Illuminate\Queue\Events\JobFailed('sync', $job, new RuntimeException('boom')));

        Monitor::flush();

        $payload = json_decode(DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'failed')->value('payload'), true);

        $this->assertSame(1, $payload['attempts']);
    }

    public function test_job_attempt_timeline_correlates_and_displays_its_notification_and_mail(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $job = new \Illuminate\Queue\Jobs\SyncJob(
            new \Illuminate\Container\Container,
            json_encode([
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'App\\Jobs\\SendWelcomeEmail', 'command' => 'x'],
                'displayName' => 'App\\Jobs\\SendWelcomeEmail',
            ]),
            'sync',
            'default',
        );

        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));

        $notifiable = new class {};
        $notification = new class {};

        event(new \Illuminate\Notifications\Events\NotificationSending($notifiable, $notification, 'mail'));

        $email = $this->emailMessage('Welcome email', 'a@b.com');
        event(new \Illuminate\Mail\Events\MessageSending($email, ['__laravel_notification' => get_class($notification)]));
        event(new \Illuminate\Mail\Events\MessageSent($this->sentMessage($email, 'a@b.com'), ['__laravel_notification' => get_class($notification)]));

        event(new \Illuminate\Notifications\Events\NotificationSent($notifiable, $notification, 'mail'));

        event(new \Illuminate\Queue\Events\JobProcessed('sync', $job));

        // recordProcessed() flushes on its own (long-running workers never
        // hit the request lifecycle) — no manual Monitor::flush() needed.
        $jobRow = DB::table('monitor_entries')->where('type', 'job')->first();
        $notificationRow = DB::table('monitor_entries')->where('type', 'notification')->first();
        $mailRow = DB::table('monitor_entries')->where('type', 'mail')->first();

        $this->assertNotNull($jobRow);
        $this->assertNotNull($jobRow->request_id);
        $this->assertSame($jobRow->request_id, $notificationRow->request_id);
        $this->assertSame($jobRow->request_id, $mailRow->request_id);

        $this->get('/monitor/jobs/attempts/'.$jobRow->request_id)
            ->assertOk()
            ->assertSeeText('SendWelcomeEmail')
            ->assertSeeText('NOTIFICATION')
            ->assertSeeText('MAIL');
    }

    public function test_job_attempt_timeline_returns_404_for_unknown_attempt(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/jobs/attempts/does-not-exist')->assertNotFound();
    }

    public function test_model_recorder_counts_hydrated_models_during_a_request(): void
    {
        DB::table('monitor_entries')->insert([
            ['type' => 'log', 'key' => 'a', 'created_at' => now()],
            ['type' => 'log', 'key' => 'b', 'created_at' => now()],
        ]);
        Monitor::flush();

        $monitor = app(\LaravelMonitor\Monitor::class);
        $monitor->beginRequest();

        LazyLoadingFixtureModel::query()->get();

        Monitor::record(RecordType::Request, 'GET /x', ['method' => 'GET', 'path' => '/x', 'status' => 200], 50, '2xx');
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->first();
        $payload = json_decode($row->payload, true);

        $this->assertGreaterThanOrEqual(2, $payload['model_count']);
    }

    public function test_model_recorder_counts_hydrated_models_during_a_job_attempt(): void
    {
        DB::table('monitor_entries')->insert([
            ['type' => 'log', 'key' => 'a', 'created_at' => now()],
        ]);
        Monitor::flush();

        $job = $this->syncJob();

        event(new \Illuminate\Queue\Events\JobProcessing('sync', $job));
        LazyLoadingFixtureModel::query()->get();
        event(new \Illuminate\Queue\Events\JobProcessed('sync', $job));

        $payload = json_decode(DB::table('monitor_entries')->where('type', 'job')->where('subtype', 'processed')->value('payload'), true);

        $this->assertGreaterThanOrEqual(1, $payload['model_count']);
    }

    public function test_lazy_loading_violation_is_recorded_and_still_throws_by_default(): void
    {
        // Eloquent only flags an individual model instance for lazy-loading
        // prevention when hydrating 2+ rows at once (Builder::hydrate()) —
        // a lone ->first() row is never flagged, since there's no N+1
        // pattern possible on a single model.
        DB::table('monitor_entries')->insert([
            ['type' => 'log', 'key' => 'a', 'created_at' => now()],
            ['type' => 'log', 'key' => 'b', 'created_at' => now()],
        ]);
        Monitor::flush();

        \Illuminate\Database\Eloquent\Model::preventLazyLoading();

        $fixture = LazyLoadingFixtureModel::query()->get()->first();
        $thrown = null;

        try {
            $fixture->related;
        } catch (\Illuminate\Database\LazyLoadingViolationException $e) {
            $thrown = $e;
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
        }

        $this->assertNotNull($thrown, 'expected the app-level default throw behaviour to still fire');

        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'lazy_loading')->first();
        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);
        $this->assertSame(LazyLoadingFixtureModel::class, $payload['model']);
        $this->assertSame('related', $payload['relation']);
    }

    public function test_format_priority_label_returns_the_human_label(): void
    {
        $this->assertSame('No priority', \LaravelMonitor\Support\Format::priorityLabel('none'));
        $this->assertSame('Urgent', \LaravelMonitor\Support\Format::priorityLabel('urgent'));
    }

    public function test_format_priority_label_falls_back_to_no_priority_for_an_unknown_value(): void
    {
        $this->assertSame('No priority', \LaravelMonitor\Support\Format::priorityLabel('made-up'));
    }

    public function test_issue_detail_page_renders_an_exception_issue(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $key = Fingerprint::for('App\\Boom', 'Kaboom', 'app/X.php:10');

        Monitor::record(RecordType::Exception, $key, [
            'class' => 'App\\Services\\Boom',
            'message' => 'Kaboom',
            'file' => 'app/X.php',
            'line' => 10,
        ], null, 'unhandled');
        Monitor::flush();

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('exception', [$key => now()]);
        $uuid = $storage->issueStatuses('exception', [$key])->get($key)->uuid;

        $this->get('/monitor/issues/'.$uuid)
            ->assertOk()
            ->assertSeeText('Boom')
            ->assertSeeText('Kaboom')
            ->assertSeeText('Manage');
    }

    public function test_issue_detail_page_renders_a_performance_issue(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record(RecordType::Query, 'select * from big_table', [], 600);
        Monitor::flush();

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('query', ['select * from big_table' => now()]);
        $uuid = $storage->issueStatuses('query', ['select * from big_table'])->get('select * from big_table')->uuid;

        $this->get('/monitor/issues/'.$uuid)
            ->assertOk()
            ->assertSeeText('Query')
            ->assertSeeText('Manage');
    }

    public function test_issue_detail_page_returns_404_for_an_unknown_uuid(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/issues/'.(string) \Illuminate\Support\Str::uuid())->assertNotFound();
    }

    public function test_updating_issue_status_persists_and_redirects_back(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
        $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

        $this->post('/monitor/issues/'.$uuid.'/status', ['status' => 'resolved'])
            ->assertRedirect('/monitor/issues/'.$uuid);

        $this->assertSame('resolved', $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->status);
    }

    public function test_updating_issue_priority_persists_and_redirects_back(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
        $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

        $this->post('/monitor/issues/'.$uuid.'/priority', ['priority' => 'urgent'])
            ->assertRedirect('/monitor/issues/'.$uuid);

        $this->assertSame('urgent', $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->priority);
    }

    public function test_updating_issue_status_rejects_an_invalid_value(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->setIssueStatus('exception', 'App\\Exceptions\\Boom', 'open');
        $uuid = $storage->issueStatuses('exception', ['App\\Exceptions\\Boom'])->get('App\\Exceptions\\Boom')->uuid;

        $this->post('/monitor/issues/'.$uuid.'/status', ['status' => 'not-a-status'])
            ->assertSessionHasErrors('status');
    }

    public function test_monitor_users_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_users', [
            'id', 'name', 'email', 'password', 'role', 'created_at', 'updated_at',
        ]));

        \Illuminate\Support\Facades\DB::table('monitor_users')->insert([
            'name' => 'Test User',
            'email' => 'test-user@example.com',
            'password' => 'irrelevant-for-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = \Illuminate\Support\Facades\DB::table('monitor_users')->where('email', 'test-user@example.com')->first();

        $this->assertSame('viewer', $row->role);
    }

    public function test_monitor_invitations_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_invitations', [
            'id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_monitor_password_resets_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_password_resets', [
            'id', 'email', 'token', 'created_at', 'updated_at',
        ]));
    }

    public function test_monitor_email_changes_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('monitor_email_changes', [
            'id', 'user_id', 'new_email', 'token', 'verified_at', 'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_monitor_user_role_helpers_reflect_the_stored_role(): void
    {
        $owner = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Owner',
            'email' => 'owner-role-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);
        $admin = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Admin',
            'email' => 'admin-role-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'admin',
        ]);
        $viewer = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Viewer',
            'email' => 'viewer-role-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'viewer',
        ]);

        $this->assertTrue($owner->canManageSettings());
        $this->assertTrue($admin->canManageSettings());
        $this->assertFalse($viewer->canManageSettings());

        $this->assertSame('monitor', \LaravelMonitor\Models\MonitorUser::guardName());
    }

    public function test_the_monitor_guard_is_registered_and_backed_by_monitor_user(): void
    {
        $user = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Guard Test',
            'email' => 'guard-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->attempt([
            'email' => 'guard-test@example.com',
            'password' => 'password',
        ]));

        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
        $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
    }

    public function test_setup_page_is_shown_when_no_users_exist(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        \LaravelMonitor\Models\MonitorUser::query()->delete();

        $this->get('/monitor/setup')
            ->assertOk()
            ->assertSeeText('Create the owner account');
    }

    public function test_setup_creates_the_first_user_as_owner_and_logs_them_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        \LaravelMonitor\Models\MonitorUser::query()->delete();

        $this->post('/monitor/setup', [
            'name' => 'First Owner',
            'email' => 'first-owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/monitor');

        $user = \LaravelMonitor\Models\MonitorUser::where('email', 'first-owner@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('owner', $user->role);
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
        $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
    }

    public function test_setup_is_unreachable_once_a_user_already_exists(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->get('/monitor/setup')->assertRedirect('/monitor/login');

        $this->post('/monitor/setup', [
            'name' => 'Second Owner',
            'email' => 'second-owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/monitor/login');

        $this->assertNull(\LaravelMonitor\Models\MonitorUser::where('email', 'second-owner@example.com')->first());
    }

    public function test_login_page_is_shown(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Existing',
            'email' => 'login-page-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->get('/monitor/login')
            ->assertOk()
            ->assertSeeText('Sign in');
    }

    public function test_login_with_correct_credentials_authenticates_and_redirects(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $user = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Login Success',
            'email' => 'login-success@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
            'role' => 'admin',
        ]);

        $this->post('/monitor/login', [
            'email' => 'login-success@example.com',
            'password' => 'correct-password',
        ])->assertRedirect('/monitor');

        $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
    }

    public function test_login_with_wrong_password_does_not_authenticate(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Login Failure',
            'email' => 'login-failure@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
            'role' => 'admin',
        ]);

        $this->post('/monitor/login', [
            'email' => 'login-failure@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    }

    public function test_login_with_wrong_password_does_not_record_monitors_own_auth_entry(): void
    {
        // Monitor's own dashboard auth (guard: 'monitor') is independent of
        // the application being monitored — Recorders\Authentication must
        // not capture it as if it were the app's own auth activity, the
        // same way Recorders\Requests already excludes the dashboard's own
        // routes from the request log.
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Login Failure',
            'email' => 'login-failure-recorded@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('correct-password'),
            'role' => 'admin',
        ]);

        $this->post('/monitor/login', [
            'email' => 'login-failure-recorded@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('monitor_entries', [
            'type' => 'auth',
        ]);
    }

    public function test_a_failed_login_on_the_monitored_applications_own_guard_is_recorded(): void
    {
        Monitor::flush();

        event(new \Illuminate\Auth\Events\Failed('web', null, ['email' => 'someone@example.com']));
        Monitor::flush();

        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'auth',
            'subtype' => 'failed',
        ]);
    }

    public function test_logout_clears_the_monitor_guard_session(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $user = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Logout Test',
            'email' => 'logout-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->actingAs($user, 'monitor');
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());

        $this->post('/monitor/logout')->assertRedirect('/monitor/login');

        $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
    }

    public function test_unauthenticated_visitor_is_redirected_to_setup_when_no_users_exist(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        \LaravelMonitor\Models\MonitorUser::query()->delete();

        $this->get('/monitor')->assertRedirect('/monitor/setup');
    }

    public function test_unauthenticated_visitor_is_redirected_to_login_when_users_exist(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Existing',
            'email' => 'redirect-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'owner',
        ]);

        $this->get('/monitor')->assertRedirect('/monitor/login');
    }

    public function test_authenticated_visitor_passes_through_to_the_dashboard(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // TestCase::setUp() already logged in a default owner.
        $this->get('/monitor')->assertOk();
    }

    public function test_the_gate_still_hard_aborts_regardless_of_auth_state(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => false);

        // TestCase::setUp()'s default owner is authenticated, but the Gate
        // is the outer, unconditional switch — it must still win.
        $this->get('/monitor')->assertForbidden();
    }

    public function test_a_viewer_cannot_post_settings_system(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $viewer = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Viewer',
            'email' => 'settings-viewer@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        $this->post('/monitor/settings/system', [])->assertForbidden();
    }

    public function test_a_viewer_cannot_post_settings_reset(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $viewer = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Viewer',
            'email' => 'settings-reset-viewer@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        $this->post('/monitor/settings/reset')->assertForbidden();
    }

    public function test_an_admin_can_post_settings_reset(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $admin = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Admin',
            'email' => 'settings-admin@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'admin',
        ]);
        $this->actingAs($admin, 'monitor');

        $this->post('/monitor/settings/reset')->assertRedirect();
    }

    public function test_monitor_user_gains_isowner_and_canmanageteam_helpers(): void
    {
        $owner = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Owner', 'email' => 'owner-helpers-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'owner',
        ]);
        $admin = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Admin', 'email' => 'admin-helpers-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
        ]);
        $viewer = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Viewer', 'email' => 'viewer-helpers-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'viewer',
        ]);

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($admin->isOwner());
        $this->assertFalse($viewer->isOwner());

        $this->assertTrue($owner->canManageTeam());
        $this->assertTrue($admin->canManageTeam());
        $this->assertFalse($viewer->canManageTeam());
    }

    public function test_monitor_invitation_create_for_generates_a_findable_token_and_expires_in_two_hours(): void
    {
        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('invitee@example.com', 'viewer', $inviter);

        $this->assertSame('invitee@example.com', $invitation->email);
        $this->assertSame('viewer', $invitation->role);
        $this->assertSame($inviter->id, $invitation->invited_by);
        $this->assertNotSame($plainToken, $invitation->token, 'the stored token must be hashed, not the plain value');
        $this->assertTrue($invitation->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
        $this->assertFalse($invitation->isExpired());

        $found = \LaravelMonitor\Models\MonitorInvitation::findByPlainToken($plainToken);
        $this->assertNotNull($found);
        $this->assertSame($invitation->id, $found->id);

        $this->assertNull(\LaravelMonitor\Models\MonitorInvitation::findByPlainToken('not-a-real-token'));
    }

    public function test_monitor_invitation_create_for_refreshes_an_existing_pending_invite_to_the_same_email(): void
    {
        $firstInviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        $secondInviter = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Second Admin', 'email' => 'second-inviter-test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
        ]);

        ['invitation' => $first] = \LaravelMonitor\Models\MonitorInvitation::createFor('re-invited@example.com', 'viewer', $firstInviter);
        ['invitation' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('re-invited@example.com', 'admin', $secondInviter);

        $this->assertSame($first->id, $second->id, 'refreshing should update the same row, not create a second one');
        $this->assertSame(1, \LaravelMonitor\Models\MonitorInvitation::where('email', 're-invited@example.com')->count());
        $this->assertSame('admin', $second->fresh()->role);
        $this->assertSame($secondInviter->id, $second->fresh()->invited_by);
        $this->assertNotNull(\LaravelMonitor\Models\MonitorInvitation::findByPlainToken($secondToken));
    }

    public function test_team_invitation_mail_links_to_the_accept_url_with_the_plain_token(): void
    {
        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('mail-test@example.com', 'viewer', $inviter);

        $mail = new \LaravelMonitor\Mail\TeamInvitationMail($invitation, $plainToken);
        $rendered = $mail->render();

        $this->assertStringContainsString('/monitor/invitations/'.$plainToken, $rendered);
        $this->assertStringContainsString($inviter->name, $rendered);
    }

    public function test_team_tab_is_registered_in_the_footer_group(): void
    {
        [, $footer] = \LaravelMonitor\Support\Nav::grouped();

        $this->assertArrayHasKey('team', $footer);
        $this->assertSame('monitor.team', $footer['team']['component']);
    }

    public function test_accept_invitation_page_is_shown_for_a_valid_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('accept-page-test@example.com', 'viewer', $inviter);

        $this->get('/monitor/invitations/'.$plainToken)
            ->assertOk()
            ->assertSeeText('accept-page-test@example.com');
    }

    public function test_accept_invitation_returns_404_for_an_unknown_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->get('/monitor/invitations/not-a-real-token')->assertNotFound();
    }

    public function test_accept_invitation_shows_an_expired_message_for_an_expired_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('expired-test@example.com', 'viewer', $inviter);
        $invitation->forceFill(['expires_at' => now()->subHour()])->save();

        $this->get('/monitor/invitations/'.$plainToken)
            ->assertOk()
            ->assertSeeText('expired');
    }

    public function test_accepting_an_invitation_creates_the_user_with_the_invited_role_and_logs_them_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['invitation' => $invitation, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('accepting@example.com', 'admin', $inviter);

        $this->post('/monitor/invitations/'.$plainToken, [
            'name' => 'Accepted Member',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/monitor');

        $user = \LaravelMonitor\Models\MonitorUser::where('email', 'accepting@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
        $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
        $this->assertNull(\LaravelMonitor\Models\MonitorInvitation::find($invitation->id));
    }

    public function test_accepting_an_already_consumed_invitation_returns_404_instead_of_erroring(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $inviter = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorInvitation::createFor('double-submit@example.com', 'viewer', $inviter);

        $payload = [
            'name' => 'Double Submit',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->post('/monitor/invitations/'.$plainToken, $payload)->assertRedirect('/monitor');
        $this->post('/monitor/invitations/'.$plainToken, $payload)->assertNotFound();

        $this->assertSame(1, \LaravelMonitor\Models\MonitorUser::where('email', 'double-submit@example.com')->count());
    }

    public function test_monitor_password_reset_create_for_hashes_the_token_and_refreshes_on_repeat_request(): void
    {
        ['reset' => $first, 'plainToken' => $firstToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('reset-test@example.com');
        ['reset' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('reset-test@example.com');

        $this->assertSame($first->id, $second->id, 'a repeat request should refresh the same row, not create a second one');
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertNotSame($firstToken, $second->token, 'the stored token must be hashed, not the plain value');
        $this->assertNotNull(\LaravelMonitor\Models\MonitorPasswordReset::findByPlainToken($secondToken));
        $this->assertNull(\LaravelMonitor\Models\MonitorPasswordReset::findByPlainToken($firstToken), 'the old token must stop working once refreshed');
    }

    public function test_monitor_password_reset_is_expired_after_60_minutes(): void
    {
        ['reset' => $reset] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('expiry-test@example.com');
        $this->assertFalse($reset->isExpired());

        $reset->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->assertTrue($reset->fresh()->isExpired());
    }

    public function test_monitor_email_change_create_for_hashes_the_token_and_is_unverified_by_default(): void
    {
        $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'new-address@example.com');

        $this->assertSame($requester->id, $emailChange->user_id);
        $this->assertSame('new-address@example.com', $emailChange->new_email);
        $this->assertNotSame($plainToken, $emailChange->token);
        $this->assertFalse($emailChange->isVerified());
        $this->assertNotNull(\LaravelMonitor\Models\MonitorEmailChange::findByPlainToken($plainToken));
        $this->assertSame($requester->id, $emailChange->user->id);
    }

    public function test_monitor_email_change_repeat_request_resets_verification(): void
    {
        $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        ['emailChange' => $first] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'first-address@example.com');
        $first->forceFill(['verified_at' => now()])->save();

        ['emailChange' => $second, 'plainToken' => $secondToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'second-address@example.com');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \LaravelMonitor\Models\MonitorEmailChange::where('user_id', $requester->id)->count());
        $this->assertSame('second-address@example.com', $second->fresh()->new_email);
        $this->assertFalse($second->fresh()->isVerified(), 'requesting again must reset verification on the refreshed row');
        $this->assertNotNull(\LaravelMonitor\Models\MonitorEmailChange::findByPlainToken($secondToken));
    }

    public function test_monitor_email_change_is_expired_after_60_minutes(): void
    {
        $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['emailChange' => $emailChange] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'expiry-change-test@example.com');

        $this->assertFalse($emailChange->isExpired());

        $emailChange->forceFill(['expires_at' => now()->subHour()])->save();
        $this->assertTrue($emailChange->fresh()->isExpired());
    }

    public function test_password_reset_mail_links_to_the_reset_url_with_the_plain_token(): void
    {
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('mail-reset-test@example.com');

        $mail = new \LaravelMonitor\Mail\PasswordResetMail($plainToken);
        $rendered = $mail->render();

        $this->assertStringContainsString('/monitor/reset-password/'.$plainToken, $rendered);
    }

    public function test_email_change_verification_mail_links_to_the_verify_url_with_the_plain_token(): void
    {
        $requester = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($requester, 'mail-verify-test@example.com');

        $mail = new \LaravelMonitor\Mail\EmailChangeVerificationMail($plainToken);
        $rendered = $mail->render();

        $this->assertStringContainsString('/monitor/email-changes/'.$plainToken, $rendered);
    }

    public function test_forgot_password_page_is_shown(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->get('/monitor/forgot-password')->assertOk();
    }

    public function test_requesting_a_reset_for_a_known_email_sends_the_mail(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        \Illuminate\Support\Facades\Mail::fake();

        $this->post('/monitor/forgot-password', ['email' => 'owner@example.com'])->assertRedirect();

        \Illuminate\Support\Facades\Mail::assertSent(\LaravelMonitor\Mail\PasswordResetMail::class);
        $this->assertNotNull(\LaravelMonitor\Models\MonitorPasswordReset::where('email', 'owner@example.com')->first());
    }

    public function test_requesting_a_reset_for_an_unknown_email_sends_nothing_but_still_redirects_the_same_way(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        \Illuminate\Support\Facades\Mail::fake();

        $knownResponse = $this->post('/monitor/forgot-password', ['email' => 'owner@example.com']);
        $unknownResponse = $this->post('/monitor/forgot-password', ['email' => 'unknown-nobody@example.com']);

        $unknownResponse->assertRedirect();
        $this->assertSame($knownResponse->headers->get('Location'), $unknownResponse->headers->get('Location'), 'the response must not reveal whether the email exists');
        \Illuminate\Support\Facades\Mail::assertSent(\LaravelMonitor\Mail\PasswordResetMail::class, 1);
        $this->assertNull(\LaravelMonitor\Models\MonitorPasswordReset::where('email', 'unknown-nobody@example.com')->first());
    }

    public function test_reset_password_page_is_shown_for_a_valid_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

        $this->get('/monitor/reset-password/'.$plainToken)->assertOk();
    }

    public function test_reset_password_returns_404_for_an_unknown_or_expired_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->get('/monitor/reset-password/not-a-real-token')->assertNotFound();

        ['reset' => $reset, 'plainToken' => $expiredToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('expired-reset-test@example.com');
        $reset->forceFill(['created_at' => now()->subMinutes(61)])->save();

        $this->get('/monitor/reset-password/'.$expiredToken)->assertNotFound();
    }

    public function test_resetting_the_password_updates_it_and_logs_the_user_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $user = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

        $this->post('/monitor/reset-password/'.$plainToken, [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('/monitor');

        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('monitor')->check());
        $this->assertSame($user->id, \Illuminate\Support\Facades\Auth::guard('monitor')->id());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_resetting_an_already_consumed_password_reset_token_returns_404_instead_of_erroring(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorPasswordReset::createFor('owner@example.com');

        $payload = ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

        $this->post('/monitor/reset-password/'.$plainToken, $payload)->assertRedirect('/monitor');
        $this->post('/monitor/reset-password/'.$plainToken, $payload)->assertNotFound();
    }

    public function test_email_change_show_page_is_shown_for_a_valid_unverified_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'verify-show-test@example.com');

        $this->get('/monitor/email-changes/'.$plainToken)
            ->assertOk()
            ->assertSeeText('verify-show-test@example.com');
    }

    public function test_email_change_show_returns_404_for_an_unknown_or_expired_token(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->get('/monitor/email-changes/not-a-real-token')->assertNotFound();

        $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['emailChange' => $emailChange, 'plainToken' => $expiredToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'expired-verify-test@example.com');
        $emailChange->forceFill(['expires_at' => now()->subHour()])->save();

        $this->get('/monitor/email-changes/'.$expiredToken)->assertNotFound();
    }

    public function test_verifying_an_owners_email_change_applies_it_immediately(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'owner-new-email@example.com');

        $this->post('/monitor/email-changes/'.$plainToken)
            ->assertOk()
            ->assertSeeText('owner-new-email@example.com');

        $this->assertSame('owner-new-email@example.com', $owner->fresh()->email);
        $this->assertNull(\LaravelMonitor\Models\MonitorEmailChange::find($emailChange->id));
    }

    public function test_verifying_a_non_owners_email_change_leaves_it_pending_for_approval(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $admin = \LaravelMonitor\Models\MonitorUser::create([
            'name' => 'Admin', 'email' => 'pending-change-admin@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange, 'plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($admin, 'admin-new-email@example.com');

        $this->post('/monitor/email-changes/'.$plainToken)->assertOk();

        $this->assertSame('pending-change-admin@example.com', $admin->fresh()->email, 'a non-owner change must not apply until approved');
        $this->assertNotNull($emailChange->fresh()->verified_at);
    }

    public function test_verifying_an_already_applied_email_change_returns_404(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $owner = \LaravelMonitor\Models\MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['plainToken' => $plainToken] = \LaravelMonitor\Models\MonitorEmailChange::createFor($owner, 'double-submit-verify@example.com');

        $this->post('/monitor/email-changes/'.$plainToken)->assertOk();
        $this->post('/monitor/email-changes/'.$plainToken)->assertNotFound();
    }
}

/**
 * Minimal Eloquent model backed by the package's own monitor_entries table
 * (already migrated in every test) purely to exercise the Models recorder's
 * retrieved-count and lazy-loading-violation hooks — its `related` relation
 * is never meant to resolve real data.
 */
class LazyLoadingFixtureModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'monitor_entries';

    public $timestamps = false;

    public function related(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'id', 'id');
    }
}
