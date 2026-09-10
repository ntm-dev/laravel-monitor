<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Monitor;

/**
 * A PHP deprecation raised by the framework or a vendor package is not the
 * application's own log output, but Testbench forwards deprecations to the
 * log by default and the Logs recorder records whatever is logged — so they
 * end up in monitor_entries next to the entries a test recorded itself. The
 * LOG_DEPRECATIONS_WHILE_TESTING=false in phpunit.xml turns that off; this
 * guards it, since the pollution otherwise only shows up on the dependency
 * versions that happen to be deprecated (e.g. Livewire 3 on PHP 8.2+).
 */
class DeprecationLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_deprecations_are_not_recorded_as_log_entries(): void
    {
        trigger_error('Creation of dynamic property Vendor\Thing::$original is deprecated', E_USER_DEPRECATED);

        $this->app->make(Monitor::class)->flush();

        $this->assertDatabaseMissing('monitor_entries', ['type' => 'log']);
    }
}
