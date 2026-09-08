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

    protected function insertRequest(string $key, CarbonImmutable $createdAt): void
    {
        DB::table('monitor_entries')->insert([
            'type' => 'request',
            'subtype' => '2xx',
            'key' => $key,
            'payload' => '[]',
            'duration' => 10,
            'user_id' => null,
            // Microsecond precision, same as DatabaseEntryWriter::store() —
            // see UsersTabTest::insertEntry().
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
