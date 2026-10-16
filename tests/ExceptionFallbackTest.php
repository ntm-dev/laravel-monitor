<?php

namespace LaravelMonitor\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LaravelMonitor\Facades\Monitor;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An application's exception handler can skip the reportable() callbacks
 * the Exceptions recorder hangs off (e.g. a report() override that returns
 * early), so exceptions the request/job lifecycle events carry are picked
 * up as a fallback at flush — unless they were already recorded.
 */
class ExceptionFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_carried_by_the_request_response_is_recorded_when_report_never_ran(): void
    {
        $this->handleRequestWith(new RuntimeException('swallowed by the app handler'));

        Monitor::flush();

        $rows = $this->exceptionRows();

        $this->assertCount(1, $rows);
        $this->assertSame('unhandled', $rows[0]->subtype);
        $this->assertSame(RuntimeException::class, json_decode($rows[0]->payload, true)['class']);
    }

    public function test_exception_reported_through_the_handler_is_recorded_once(): void
    {
        $exception = new RuntimeException('reported normally');

        app(ExceptionHandler::class)->report($exception);
        $this->handleRequestWith($exception);

        Monitor::flush();

        $this->assertCount(1, $this->exceptionRows());
    }

    public function test_exception_the_handler_would_not_report_is_ignored(): void
    {
        $this->handleRequestWith(new NotFoundHttpException);
        $this->handleRequestWith(ValidationException::withMessages(['name' => 'required']));

        Monitor::flush();

        $this->assertCount(0, $this->exceptionRows());
    }

    public function test_exception_from_a_job_attempt_is_recorded_when_report_never_ran(): void
    {
        $job = new SyncJob(new Container, json_encode(['job' => 'x', 'data' => []]), 'sync', 'default');

        event(new JobExceptionOccurred('sync', $job, new RuntimeException('job blew up')));

        Monitor::flush();

        $this->assertCount(1, $this->exceptionRows());
    }

    public function test_same_exception_seen_by_several_events_is_recorded_once(): void
    {
        $job = new SyncJob(new Container, json_encode(['job' => 'x', 'data' => []]), 'sync', 'default');
        $exception = new RuntimeException('seen twice');

        event(new JobExceptionOccurred('sync', $job, $exception));
        event(new JobExceptionOccurred('sync', $job, $exception));

        Monitor::flush();

        $this->assertCount(1, $this->exceptionRows());
    }

    private function handleRequestWith(\Throwable $exception): void
    {
        event(new RequestHandled(Request::create('/menus/import'), (new Response)->withException($exception)));
    }

    /**
     * @return list<object>
     */
    private function exceptionRows(): array
    {
        return DB::table('monitor_entries')->where('type', 'exception')->get()->all();
    }
}
