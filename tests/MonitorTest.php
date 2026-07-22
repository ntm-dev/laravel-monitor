<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Support\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_entries_and_flushes_to_storage(): void
    {
        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record('request', 'GET /users', ['status' => 200], 80, '2xx', 1);
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
        Monitor::record('request', 'GET /users', [], 100, '2xx');
        Monitor::record('request', 'GET /users', [], 300, '2xx');
        Monitor::record('request', 'GET /posts', [], 50, '4xx');
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
        Monitor::record('request', 'GET /users', [], 100, '2xx');
        Monitor::record('request', 'GET /users', [], 300, '2xx');
        Monitor::record('request', 'GET /posts', [], 50, '2xx');
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
        Monitor::record('exception', 'RuntimeException', ['message' => 'boom']);
        Monitor::flush();

        $storage = app(Storage::class);
        $since = CarbonImmutable::now()->subHour();

        $this->assertSame(1, $storage->stats('exception', $since)->count);
        $this->assertSame('boom', $storage->recent('exception', $since)->first()->payload['message']);

        $storage->purge();
        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_slow_query_recorder_captures_every_query_and_tags_slow_ones(): void
    {
        config(['monitor.recorders.'.\LaravelMonitor\Recorders\Queries::class.'.threshold' => 100]);

        event(new QueryExecuted('select * from users', [], 250.0, DB::connection()));
        event(new QueryExecuted('select * from posts', [], 5.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 2);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'slow_query',
            'key' => 'select * from users',
            'duration' => 250,
            'subtype' => 'slow',
        ]);
        $this->assertDatabaseHas('monitor_entries', [
            'type' => 'slow_query',
            'key' => 'select * from posts',
            'duration' => 5,
            'subtype' => 'fast',
        ]);
    }

    public function test_slow_query_recorder_normalizes_in_clauses_and_bulk_inserts_into_one_group(): void
    {
        event(new QueryExecuted('select * from users where id in (?, ?, ?)', [], 10.0, DB::connection()));
        event(new QueryExecuted('select * from users where id in (?, ?, ?, ?, ?)', [], 20.0, DB::connection()));
        event(new QueryExecuted('insert into logs (a, b) values (?, ?), (?, ?), (?, ?)', [], 5.0, DB::connection()));

        Monitor::flush();

        $keys = DB::table('monitor_entries')->where('type', 'slow_query')->pluck('key');

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

    public function test_slow_query_recorder_ignores_its_own_storage_table(): void
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
    public function test_slow_query_recorder_ignores_all_of_monitors_own_tables(string $table): void
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

    protected function commandEvents(string $command): array
    {
        return [
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        ];
    }

    public function test_command_recorder_captures_exit_code_and_duration(): void
    {
        [$input, $output] = $this->commandEvents('app:sync-data');

        event(new \Illuminate\Console\Events\CommandStarting('app:sync-data', $input, $output));
        usleep(1000);
        event(new \Illuminate\Console\Events\CommandFinished('app:sync-data', $input, $output, 0));

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

        $row = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->assertSame('failed', $row->subtype);
        $this->assertSame(1, json_decode($row->payload, true)['exit_code']);
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

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();
        $queryRow = DB::table('monitor_entries')->where('type', 'slow_query')->first();

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

        $commandRow = DB::table('monitor_entries')->where('type', 'command')->first();

        $this->get('/monitor/commands/runs/'.$commandRow->request_id)
            ->assertOk()
            ->assertSeeText('app:sync-data')
            ->assertSeeText('QUERY');
    }

    public function test_command_run_page_returns_404_for_unknown_run(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->get('/monitor/commands/runs/does-not-exist')->assertNotFound();
    }

    public function test_recording_can_be_disabled(): void
    {
        config(['monitor.enabled' => false]);

        Monitor::record('request', 'GET /users');
        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
    }

    public function test_exception_recorder_fingerprints_and_classifies(): void
    {
        $exception = new RuntimeException('Charge declined for order 4821');

        event(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));
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

    public function test_exception_recorder_marks_lower_levels_as_handled(): void
    {
        $exception = new RuntimeException('Retrying webhook');

        event(new MessageLogged('warning', $exception->getMessage(), ['exception' => $exception]));
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

        Monitor::record('exception', $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'unhandled', 1);
        Monitor::record('exception', $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'unhandled', 2);
        Monitor::record('exception', $key, ['class' => 'App\\Boom', 'message' => 'Kaboom'], null, 'handled', 2);
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

        Monitor::record('exception', $key, [
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

    public function test_dashboard_is_protected_by_gate(): void
    {
        $this->get('/monitor')->assertForbidden();
    }

    public function test_dashboard_renders_when_authorized(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx');
        Monitor::flush();

        $this->get('/monitor')
            ->assertOk()
            ->assertSee('Monitor')
            ->assertSee('Requests');
    }

    public function test_every_tab_renders(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        Monitor::record('request', 'GET /users', ['status' => 200], 120, '2xx', 1);
        Monitor::record('exception', 'RuntimeException', ['class' => 'RuntimeException', 'message' => 'boom', 'file' => 'app/X.php', 'line' => 1]);
        Monitor::record('slow_query', 'select * from users', ['sql' => 'select * from users'], 250);
        Monitor::record('job', 'App\\Jobs\\SendEmail', ['queue' => 'default'], 40, 'processed');
        Monitor::record('command', 'app:sync-data', ['exit_code' => 0], 15, 'success');
        Monitor::record('scheduled_task', 'inspire', ['command' => 'inspire'], 12, 'finished');
        Monitor::record('cache', 'users:1', [], null, 'hit');
        Monitor::record('outgoing_request', 'GET https://api.example.com', ['status' => 200], 90, 'success');
        Monitor::record('mail', 'Welcome', ['subject' => 'Welcome', 'to' => 'a@b.c']);
        Monitor::record('notification', 'App\\Notifications\\Invoice', ['channel' => 'mail'], null, 'mail');
        Monitor::record('log', 'Something happened', ['message' => 'Something happened', 'level' => 'warning'], null, 'warning');
        Monitor::record('auth', 'a@b.c', ['guard' => 'web'], null, 'login', 1);
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

        Monitor::record('request', 'GET /users', ['status' => 200], 50, '2xx', 1);
        Monitor::record('request', 'POST /users', ['status' => 201], 60, '2xx', 1);
        Monitor::record('request', 'PUT /users/1', ['status' => 200], 70, '2xx', 1);
        Monitor::record('request', 'PATCH /users/1', ['status' => 200], 40, '2xx', 1);
        Monitor::record('request', 'DELETE /users/1', ['status' => 204], 30, '2xx', 1);
        Monitor::record('request', 'GET /orders', ['status' => 404], 20, '4xx', 1);
        Monitor::record('request', 'POST /payments', ['status' => 500], 90, '5xx', 1);
        Monitor::flush();

        $this->get('/monitor/requests')
            ->assertOk()
            ->assertSee('text-emerald-600', false)
            ->assertSee('text-blue-500', false)
            ->assertSee('text-rose-600', false)
            ->assertSee('fill-amber-500', false)
            ->assertSee('fill-rose-500', false);
    }

    public function test_entries_recorded_during_a_request_are_correlated(): void
    {
        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();
        Monitor::record('slow_query', 'select * from users', ['sql' => 'select * from users'], 25);
        $monitor->markResponseReady();
        Monitor::record('request', 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
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
        $this->assertSame('slow_query', $children->first()->type);
        $this->assertSame($requestId, $children->first()->request_id);
        $this->assertIsNumeric($children->first()->start_offset);
    }

    public function test_request_recorder_captures_correlated_timeline_end_to_end(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/demo-users', function () {
            Monitor::record('slow_query', 'select * from users', ['sql' => 'select * from users'], 25);

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

        $query = \Illuminate\Support\Facades\DB::table('monitor_entries')->where('type', 'slow_query')->first();

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
        Monitor::record('slow_query', 'select * from users', ['sql' => 'select * from users'], 25);
        Monitor::record('request', 'GET /users', ['method' => 'GET', 'path' => '/users', 'status' => 200], 120, '2xx', 1);
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

        $this->get('/monitor/notifications?key='.$notificationId)
            ->assertOk()
            ->assertSee('View sent email')
            ->assertSee('mail?key='.$mailId, false);

        $this->get('/monitor/mail?key='.$mailId)
            ->assertOk()
            ->assertSee('Sent via notification')
            ->assertSee('notifications?key='.$notificationId, false);
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
        Monitor::record('notification', $key, ['notification' => $key, 'channel' => 'mail'], 10, 'mail');
        Monitor::record('notification', $key, ['notification' => $key, 'channel' => 'mail'], 20, 'mail');
        Monitor::record('notification', $key, ['notification' => $key, 'channel' => 'database'], null, 'database');
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
        Monitor::record('mail', $key, ['subject' => 'Your invoice', 'to' => 'a@b.com', 'mailable' => $key], 5, 'direct');
        Monitor::record('mail', $key, ['subject' => 'Your invoice', 'to' => 'c@d.com', 'mailable' => $key], 8, 'direct');
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

        Monitor::record('request', 'GET /x', ['method' => 'GET', 'path' => '/x', 'status' => 200], 50, '2xx');
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

        Monitor::record('exception', $key, [
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

        Monitor::record('slow_query', 'select * from big_table', [], 600);
        Monitor::flush();

        $storage = app(\LaravelMonitor\Contracts\Storage::class);
        $storage->syncIssues('slow_query', ['select * from big_table' => now()]);
        $uuid = $storage->issueStatuses('slow_query', ['select * from big_table'])->get('select * from big_table')->uuid;

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
