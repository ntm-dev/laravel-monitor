<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\Settings;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TestCase force-enables the heavy recorders via this same override
     * file for every test — reset it so "nothing saved yet" assertions here
     * describe Settings in isolation, not layered on that baseline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Settings::reset();
    }

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

        $path = app()->bootstrapPath('cache/monitor-settings.php');

        $this->assertFileExists($path);
        $this->assertStringStartsWith('<?php', file_get_contents($path));

        // Prove it's really a require()-able PHP array file, not JSON —
        // the whole point of the format (see Settings::write()).
        $this->assertSame(['enabled' => false, 'refresh' => 30], require $path);
    }

    public function test_reset_deletes_the_file_and_clears_saved_overrides(): void
    {
        Settings::save(['enabled' => false]);
        $this->assertFileExists(app()->bootstrapPath('cache/monitor-settings.php'));

        Settings::reset();

        $this->assertFileDoesNotExist(app()->bootstrapPath('cache/monitor-settings.php'));
        $this->assertSame([], Settings::all());
    }

    public function test_apply_overlays_saved_overrides_onto_live_config(): void
    {
        Settings::save(['refresh' => 42]);

        Settings::apply();

        $this->assertSame(42, config('monitor.refresh'));
    }

    public function test_apply_overlays_a_table_prefix_onto_every_table(): void
    {
        Settings::save(['table_prefix' => 'custom_']);

        Settings::apply();

        $this->assertSame('custom_', config('monitor.table_prefix'));
        $this->assertSame('custom_entries', config('monitor.storage.database.table'));
        $this->assertSame('custom_aggregates', config('monitor.aggregates.table'));
        $this->assertSame('custom_issues', config('monitor.issues.table'));
        $this->assertSame('custom_users', config('monitor.auth.table'));
        $this->assertSame('custom_invitations', config('monitor.auth.invitations_table'));
        $this->assertSame('custom_password_resets', config('monitor.auth.password_resets_table'));
        $this->assertSame('custom_email_changes', config('monitor.auth.email_changes_table'));
        $this->assertSame('custom_webauthn_credentials', config('monitor.auth.webauthn_table'));
        $this->assertSame('custom_oauth_accounts', config('monitor.auth.oauth_accounts_table'));
    }

    public function test_table_suffixes_names_every_table_this_package_creates(): void
    {
        $this->assertSame([
            'entries', 'aggregates', 'issues', 'users', 'invitations',
            'password_resets', 'email_changes', 'webauthn_credentials', 'oauth_accounts',
        ], Settings::tableSuffixes());
    }

    public function test_recorders_columns_split_the_full_recorder_list_evenly(): void
    {
        $recorders = Settings::recorders();
        $columns = Settings::recorderColumns();

        $this->assertCount(2, $columns);
        $this->assertSame($recorders, array_merge(...$columns));
    }

    public function test_recorders_warning_names_every_heavy_recorder(): void
    {
        $warning = Settings::recordersWarning();

        foreach (['Queries', 'Models', 'CacheInteractions'] as $name) {
            $this->assertStringContainsString($name, $warning);
        }

        // Essential enough to always default on — not a "heavy" recorder.
        $this->assertStringNotContainsString('Requests', $warning);
    }
}
