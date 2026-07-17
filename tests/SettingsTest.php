<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\Settings;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Settings::reset();

        parent::tearDown();
    }

    public function test_all_returns_empty_array_when_nothing_saved(): void
    {
        $this->assertSame([], Settings::all());
    }

    public function test_save_persists_a_php_file_that_all_reads_back(): void
    {
        Settings::save(['enabled' => false, 'refresh' => 30]);

        $this->assertSame(['enabled' => false, 'refresh' => 30], Settings::all());

        $path = storage_path('app/monitor-settings.php');

        $this->assertFileExists($path);
        $this->assertStringStartsWith('<?php', file_get_contents($path));

        // Prove it's really a require()-able PHP array file, not JSON —
        // the whole point of the format (see Settings::write()).
        $this->assertSame(['enabled' => false, 'refresh' => 30], require $path);
    }

    public function test_reset_deletes_the_file_and_clears_saved_overrides(): void
    {
        Settings::save(['enabled' => false]);
        $this->assertFileExists(storage_path('app/monitor-settings.php'));

        Settings::reset();

        $this->assertFileDoesNotExist(storage_path('app/monitor-settings.php'));
        $this->assertSame([], Settings::all());
    }

    public function test_apply_overlays_saved_overrides_onto_live_config(): void
    {
        Settings::save(['refresh' => 42]);

        Settings::apply();

        $this->assertSame(42, config('monitor.refresh'));
    }
}
