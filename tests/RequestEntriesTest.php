<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Support\RecordType;

class RequestEntriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewMonitor', fn ($user = null) => true);
    }

    public function test_status_text_shows_next_to_the_status_code(): void
    {
        $requestId = $this->seedRequest();

        $this->get(route('monitor.requests.show', $requestId))
            ->assertOk()
            ->assertSee('Not Found');
    }

    public function test_page_defers_both_cards_instead_of_rendering_them_inline(): void
    {
        $requestId = $this->seedRequest(withEntries: true);

        $this->get(route('monitor.requests.show', $requestId))
            ->assertOk()
            // The url is JSON-encoded into the Alpine attribute, so its slashes are escaped.
            ->assertSee(str_replace('/', '\/', route('monitor.requests.exceptions', $requestId)), false)
            ->assertSee(str_replace('/', '\/', route('monitor.requests.logs', $requestId)), false);
    }

    public function test_exceptions_fragment_lists_the_requests_exceptions(): void
    {
        $requestId = $this->seedRequest(withEntries: true);

        $this->get(route('monitor.requests.exceptions', $requestId))
            ->assertOk()
            ->assertSee('RuntimeException')
            ->assertSee('Payment failed')
            ->assertSee('Unhandled');
    }

    public function test_logs_fragment_lists_the_requests_logs(): void
    {
        $requestId = $this->seedRequest(withEntries: true);

        $this->get(route('monitor.requests.logs', $requestId))
            ->assertOk()
            ->assertSee('Card declined')
            ->assertSee('error');
    }

    public function test_fragments_are_empty_when_the_request_has_none(): void
    {
        $requestId = $this->seedRequest();

        $this->get(route('monitor.requests.exceptions', $requestId))->assertNoContent();
        $this->get(route('monitor.requests.logs', $requestId))->assertNoContent();
    }

    public function test_fragments_only_include_their_own_requests_entries(): void
    {
        $this->seedRequest(withEntries: true);
        $other = $this->seedRequest();

        $this->get(route('monitor.requests.exceptions', $other))->assertNoContent();
        $this->get(route('monitor.requests.logs', $other))->assertNoContent();
    }

    protected function seedRequest(bool $withEntries = false): string
    {
        $monitor = app(\LaravelMonitor\Monitor::class);

        $monitor->beginRequest();
        $monitor->markControllerStart();

        if ($withEntries) {
            Monitor::record(RecordType::Exception, 'fingerprint-1', [
                'class' => 'RuntimeException',
                'message' => 'Payment failed',
                'file' => '/app/Pay.php',
                'line' => 42,
            ], null, 'unhandled');
            Monitor::record(RecordType::Log, 'Card declined', [
                'message' => 'Card declined',
                'context' => '{"card":"1234"}',
            ], null, 'error');
        }

        Monitor::record(RecordType::Request, 'GET /pay', ['request' => ['method' => 'GET', 'path' => '/pay'], 'response' => ['status' => 404]], 120, '4xx');
        Monitor::flush();

        return $monitor->requestId();
    }
}
