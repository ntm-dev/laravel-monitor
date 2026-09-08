<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Livewire\Requests;
use Livewire\Livewire;

/**
 * The rest of the suite runs on the default UTC app timezone, where a window
 * boundary tagged UTC and one tagged app-timezone bind the same string — so
 * only a non-UTC app can catch a boundary built in the wrong zone.
 */
class TimezoneWindowTest extends TestCase
{
    use RefreshDatabase;

    protected const APP_TIMEZONE = 'Asia/Tokyo';

    public function test_a_live_window_only_reaches_back_its_own_length(): void
    {
        config(['app.timezone' => self::APP_TIMEZONE]);

        $now = CarbonImmutable::now(self::APP_TIMEZONE);

        $this->insertRequest('GET /recent', $now->subMinutes(5));
        // Outside the 1h default period, but inside it by the app's UTC
        // offset if the boundary reaches the query as a UTC wall-clock.
        $this->insertRequest('GET /old', $now->subMinutes(90));

        $routes = Livewire::test(Requests::class)->viewData('routes');

        $this->assertSame(['GET /recent'], $routes->pluck('key')->all());
    }

    public function test_a_window_boundary_is_expressed_in_the_apps_timezone(): void
    {
        config(['app.timezone' => self::APP_TIMEZONE]);

        $since = (fn () => $this->since())->call(new Requests());

        $this->assertSame(self::APP_TIMEZONE, $since->timezone->getName());
    }

    /** `created_at` as app-timezone wall-clock, the way DatabaseEntryWriter::store() writes it. */
    protected function insertRequest(string $key, CarbonImmutable $createdAt): void
    {
        DB::table('monitor_entries')->insert([
            'type' => 'request',
            'subtype' => '2xx',
            'key' => $key,
            'payload' => '[]',
            'duration' => 10,
            'user_id' => null,
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
