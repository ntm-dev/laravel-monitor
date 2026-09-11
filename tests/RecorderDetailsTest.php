<?php

namespace LaravelMonitor\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LaravelMonitor\Facades\Monitor;
use ReflectionClass;
use Symfony\Component\Mime\Email;

/**
 * config/monitor.php's recorders.*.details toggles — off by default outside
 * local (Settings::RECORDER_DETAILS) — need forcing on before boot via
 * TestCase::writeSettingsOverride(), since a config() call from inside a
 * test body never reaches the already-constructed Recorder instance.
 */
class RecorderDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function resolveApplicationEnvironmentVariables($app): void
    {
        parent::resolveApplicationEnvironmentVariables($app);

        $this->writeSettingsOverride($app, [
            'recorder_details' => [
                'Queries' => ['trace' => true],
                'Jobs' => ['trace' => true],
                'OutgoingRequests' => ['trace' => true, 'record_body' => true],
                'Mail' => ['record_body' => true],
                'Notifications' => ['record_data' => true],
            ],
        ]);
    }

    public function test_query_trace_is_captured_once_its_detail_toggle_is_on(): void
    {
        event(new QueryExecuted('select * from users', [], 5.0, DB::connection()));
        Monitor::flush();

        $trace = $this->latestPayload('query')['trace'];

        $this->assertIsString($trace);
        $this->assertMatchesRegularExpression('/^#0 .+\(\d+\): .+\(\)/', $trace);
    }

    public function test_job_trace_is_captured_once_its_detail_toggle_is_on(): void
    {
        event($this->queuedEvent());
        Monitor::flush();

        $trace = $this->latestPayload('job')['trace'];

        $this->assertIsString($trace);
        $this->assertStringStartsWith('#0 ', $trace);
    }

    public function test_outgoing_request_trace_and_body_are_captured_once_enabled(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Http::post('https://api.example.test/orders', ['token' => 'shh', 'amount' => 10]);
        Monitor::flush();

        $payload = $this->latestPayload('outgoing_request');

        $this->assertIsString($payload['trace']);
        $this->assertStringNotContainsString('shh', $payload['request_body']);
        $this->assertStringContainsString('redacted', $payload['request_body']);
        $this->assertStringContainsString('"ok":true', $payload['response_body']);
    }

    public function test_mail_body_is_captured_once_its_detail_toggle_is_on(): void
    {
        $email = new Email;
        $email->subject('Welcome')->to('a@b.com')->from('noreply@x.com')->html('<p>Hello there</p>');

        event(new MessageSending($email, ['__laravel_mailable' => 'App\\Mail\\Welcome']));
        event(new MessageSent($this->sentMessage($email, 'a@b.com'), ['__laravel_mailable' => 'App\\Mail\\Welcome']));
        Monitor::flush();

        $this->assertStringContainsString('Hello there', $this->latestPayload('mail')['body']);
    }

    public function test_notification_data_is_captured_once_its_detail_toggle_is_on(): void
    {
        $notifiable = new class {};
        $notification = new class
        {
            public function toArray($notifiable): array
            {
                return ['order_id' => 42];
            }
        };

        event(new NotificationSent($notifiable, $notification, 'database'));
        Monitor::flush();

        $this->assertSame(['order_id' => 42], $this->latestPayload('notification')['data']);
    }

    /** @return array<string, mixed> */
    protected function latestPayload(string $type): array
    {
        $row = DB::table('monitor_entries')->where('type', $type)->latest('id')->first();

        return json_decode($row->payload, true);
    }

    private function sentMessage(Email $email, string $to): \Illuminate\Mail\SentMessage
    {
        $envelope = new \Symfony\Component\Mailer\Envelope(
            new \Symfony\Component\Mime\Address('noreply@x.com'),
            [new \Symfony\Component\Mime\Address($to)],
        );

        return new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, $envelope));
    }

    protected function job(): SyncJob
    {
        return new SyncJob(new Container, $this->jobPayload(), 'sync', 'default');
    }

    protected function jobPayload(): string
    {
        return json_encode([
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\\Jobs\\SendWelcomeEmail', 'command' => 'x'],
            'displayName' => 'App\\Jobs\\SendWelcomeEmail',
            'uuid' => 'job-detail-1',
        ]);
    }

    /** Same version-compatibility dance as JobDispatchLocationTest::queuedEvent(). */
    protected function queuedEvent(): JobQueued
    {
        $args = [
            'connectionName' => 'sync',
            'queue' => 'default',
            'id' => 'job-detail-1',
            'job' => $this->job(),
            'payload' => $this->jobPayload(),
            'delay' => null,
        ];

        $accepted = collect((new ReflectionClass(JobQueued::class))->getConstructor()->getParameters())->pluck('name');

        return new JobQueued(...collect($args)->only($accepted)->all());
    }
}
