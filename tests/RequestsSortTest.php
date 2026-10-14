<?php

namespace LaravelMonitor\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelMonitor\Livewire\Requests;
use Livewire\Livewire;

class RequestsSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_list_can_sort_by_last_seen(): void
    {
        $this->seedRoutes();

        $component = Livewire::test(Requests::class)->call('sort', 'last_seen');

        $this->assertSame('last_seen', $component->get('sortBy'));
        $this->assertSame('desc', $component->get('sortDirection'));
        $this->assertSame('GET /newest', $component->viewData('routes')->first()->key);

        $component->call('sort', 'last_seen');

        $this->assertSame('asc', $component->get('sortDirection'));
        $this->assertSame('GET /oldest', $component->viewData('routes')->first()->key);
    }

    /**
     * The auto-refresh driver in components/layout.blade.php calls $refresh
     * on every polling root, so the chosen sort has to survive one.
     */
    public function test_sort_survives_an_auto_refresh(): void
    {
        $this->seedRoutes();

        $component = Livewire::test(Requests::class)->call('sort', 'last_seen');

        $component->call('$refresh');

        $this->assertSame('last_seen', $component->get('sortBy'));
        $this->assertSame('desc', $component->get('sortDirection'));
        $this->assertSame('GET /newest', $component->viewData('routes')->first()->key);
    }

    protected function seedRoutes(): void
    {
        $now = CarbonImmutable::now();

        $this->insertRequest('GET /oldest', $now->subMinutes(30));
        $this->insertRequest('GET /oldest', $now->subMinutes(29));
        $this->insertRequest('GET /oldest', $now->subMinutes(28));
        $this->insertRequest('GET /newest', $now->subMinutes(2));
    }

    /**
     * A route with one slow outlier has a low avg/p95 but a high max — the
     * threshold tab matches on max, so it still shows up there.
     */
    public function test_threshold_filter_matches_on_the_routes_max_duration(): void
    {
        $now = CarbonImmutable::now();

        foreach (range(1, 100) as $i) {
            $this->insertRequest('GET /spiky', $now->subMinutes(10), 10);
        }
        $this->insertRequest('GET /spiky', $now->subMinutes(9), 5000);
        $this->insertRequest('GET /steady', $now->subMinutes(8), 20);

        $component = Livewire::test(Requests::class)->call('setDurationFilter', 'threshold');

        $this->assertSame(['GET /spiky'], $component->viewData('routes')->pluck('key')->all());
        $this->assertSame(5000.0, (float) $component->viewData('routes')->first()->max_duration);
    }

    protected function insertRequest(string $key, CarbonImmutable $createdAt, float $duration = 10): void
    {
        DB::table('monitor_entries')->insert([
            'type' => 'request',
            'subtype' => '2xx',
            'key' => $key,
            'payload' => '[]',
            'duration' => $duration,
            'user_id' => null,
            // Microsecond precision, same as DatabaseEntryWriter::store() —
            // see UsersTabTest::insertEntry().
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
