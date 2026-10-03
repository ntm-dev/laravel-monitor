<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelMonitor\Support\EntryId;
use LaravelMonitor\Support\KeyHash;

/**
 * A single outgoing call's page mirrors the request page: General, Request
 * and Response sections.
 */
class OutgoingDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_general_request_and_response_sections(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $id = DB::table('monitor_entries')->insertGetId([
            'type' => 'outgoing_request', 'subtype' => '2xx', 'key' => 'api.example.com',
            'payload' => json_encode([
                'method' => 'POST', 'url' => 'https://api.example.com/v1/orders', 'status' => 201,
                'server' => 'web-1',
                'request_headers' => ['content-type' => 'application/json'],
                'request_size' => 20,
                'request_body' => '{"sku":"abc"}',
                'response_headers' => ['x-request-id' => 'req-123'],
                'response_size' => 15,
                'response_body' => '{"ok":true}',
            ]),
            'duration' => 120, 'created_at' => now(),
        ]);

        $this->get('/monitor/outgoing/'.KeyHash::for('api.example.com').'/'.EntryId::encode($id))
            ->assertOk()
            // Header: host breadcrumb, path as the heading, full URL underneath.
            ->assertSee('api.example.com')
            ->assertSee('/v1/orders')
            ->assertSee('General')
            ->assertSee('https://api.example.com/v1/orders')
            ->assertSee('web-1')
            ->assertSee('Request')
            ->assertSee('Response')
            ->assertSee('req-123', false)
            // Expanded by default, with the directive compiled rather than left as text.
            ->assertSee('open: true, bodyCopied: false', false)
            ->assertDontSee('@js', false);
    }
}
