<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LaravelMonitor\Facades\Monitor;

/**
 * The Settings "Max body size" override reaches the recorder only through
 * the settings file written before boot (see RecorderDetailsTest).
 */
class RequestBodyMaxSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function resolveApplicationEnvironmentVariables($app): void
    {
        parent::resolveApplicationEnvironmentVariables($app);

        $this->writeSettingsOverride($app, [
            'recorder_details' => [
                'Requests' => ['record_response_body' => true],
            ],
            'request_body_max_bytes' => 1024,
        ]);
    }

    public function test_response_body_is_cut_at_the_configured_size(): void
    {
        Route::get('/demo-capped', static fn () => response()->json(['data' => str_repeat('a', 5000)]));

        $this->get('/demo-capped')->assertOk();
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->latest('id')->first();
        $body = json_decode($row->payload, true)['response']['body'];

        $this->assertIsString($body);
        $this->assertStringEndsWith('…', $body);
        // 1024 bytes of JSON plus the 3-byte ellipsis.
        $this->assertSame(1024 + 3, strlen($body));
    }

    public function test_body_under_the_configured_size_is_kept_whole(): void
    {
        Route::get('/demo-small', static fn () => response()->json(['data' => 'ok']));

        $this->get('/demo-small')->assertOk();
        Monitor::flush();

        $row = DB::table('monitor_entries')->where('type', 'request')->latest('id')->first();

        $this->assertSame(['data' => 'ok'], json_decode($row->payload, true)['response']['body']);
    }
}
