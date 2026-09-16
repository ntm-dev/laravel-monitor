<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LaravelMonitor\Facades\Monitor;

/**
 * The settings override file persists across test classes, so the toggle is
 * forced off explicitly rather than relying on the non-local default.
 */
class RequestResponseBodyDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function resolveApplicationEnvironmentVariables($app): void
    {
        parent::resolveApplicationEnvironmentVariables($app);

        $this->writeSettingsOverride($app, [
            'recorder_details' => [
                'Requests' => ['record_response_body' => false],
            ],
        ]);
    }

    public function test_response_body_is_not_captured_while_its_detail_toggle_is_off(): void
    {
        Route::get('/demo-token', static fn () => response()->json(['token' => 'shh']));

        $this->get('/demo-token')->assertOk();
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->latest('id')->first();
        $payload = json_decode($row->payload, true);

        $this->assertSame(200, $payload['response']['status']);
        $this->assertArrayNotHasKey('body', $payload['response']);
        $this->assertStringNotContainsString('shh', $row->payload);
    }
}
