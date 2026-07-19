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

    public function test_slow_query_recorder_ignores_its_own_storage_table(): void
    {
        event(new QueryExecuted('select * from monitor_entries', [], 1.0, DB::connection()));

        Monitor::flush();

        $this->assertDatabaseCount('monitor_entries', 0);
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
    private function cacheEvent(string $eventClass, string $key, mixed $value = null): object
    {
        $reflection = new ReflectionClass($eventClass);

        $arguments = array_map(
            fn ($parameter) => match ($parameter->getName()) {
                'storeName' => null,
                'key' => $key,
                'value' => $value,
                default => $parameter->getDefaultValue(),
            },
            $reflection->getConstructor()->getParameters(),
        );

        return $reflection->newInstanceArgs($arguments);
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
        Monitor::record('scheduled_task', 'inspire', ['command' => 'inspire'], 12, 'finished');
        Monitor::record('cache', 'users:1', [], null, 'hit');
        Monitor::record('outgoing_request', 'GET https://api.example.com', ['status' => 200], 90, 'success');
        Monitor::record('mail', 'Welcome', ['subject' => 'Welcome', 'to' => 'a@b.c']);
        Monitor::record('notification', 'App\\Notifications\\Invoice', ['channel' => 'mail'], null, 'mail');
        Monitor::record('log', 'Something happened', ['message' => 'Something happened', 'level' => 'warning'], null, 'warning');
        Monitor::record('auth', 'a@b.c', ['guard' => 'web'], null, 'login', 1);
        Monitor::flush();

        foreach (['overview', 'requests', 'exceptions', 'queries', 'jobs', 'schedule', 'cache', 'outgoing', 'mail', 'notifications', 'users', 'logs'] as $tab) {
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
}
