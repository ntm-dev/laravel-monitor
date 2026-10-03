<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\Trace;

/**
 * With the 'trace' detail on, the call site is read back from the stored
 * trace instead of being stored a second time as 'location'.
 */
class TraceLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_is_the_first_application_frame_of_the_trace(): void
    {
        $base = base_path();
        $trace = implode("\n", [
            '#0 vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php(10): Dispatcher->dispatch()',
            "#1 app/Services/Report.php(42): Foo->bar()",
            '#2 vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(5): Pipeline->run()',
        ]);

        $this->assertSame('app/Services/Report.php:42', Trace::location($trace));
        $this->assertSame("{$base}/app/Services/Report.php:42", Trace::location($trace, absolute: true));
    }

    public function test_location_is_null_without_a_trace(): void
    {
        $this->assertNull(Trace::location(null));
        $this->assertNull(Trace::location(''));
    }
}
