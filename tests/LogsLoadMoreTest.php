<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Facades\Monitor;
use LaravelMonitor\Livewire\Logs;
use LaravelMonitor\Support\RecordType;
use Livewire\Livewire;

/**
 * Logs pages by an id keyset cursor instead of an ever-growing limit/offset,
 * and never routes the accumulated list back through Livewire's own state
 * (see Logs::$oldestId/$newestId/$bootstrapped) — every batch past the first
 * is dispatched as rendered HTML for logs.blade.php's JS to splice directly
 * into the DOM, so the wire:snapshot stays small no matter how long the list
 * grows. These tests inspect the dispatched HTML rather than
 * viewData('logs'), which — by design — only ever carries rows on the very
 * first render.
 */
class LogsLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    private function recordLogs(int $count, string $prefix = 'log', string $level = 'error'): void
    {
        foreach (range(1, $count) as $i) {
            Monitor::record(RecordType::Log, 'log', ['message' => "{$prefix} {$i}"], null, $level);
        }

        Monitor::flush();
    }

    /**
     * Each dispatched batch's summaries, in order, read off logs-entry.blade
     * .php's own data-tooltip="{{ $log->summary }}" attribute.
     *
     * @return string[]
     */
    private function summariesIn(string $html): array
    {
        preg_match_all('/data-tooltip="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    public function test_load_more_appends_the_next_older_batch_as_rendered_html(): void
    {
        $this->recordLogs(65);

        $component = Livewire::test(Logs::class);

        $first = $component->viewData('logs');
        $this->assertCount(50, $first);
        $this->assertTrue($component->viewData('hasMore'));
        $this->assertSame('log 65', $first->first()->summary);
        $this->assertSame('log 16', $first->last()->summary);

        $component->call('loadMore');
        $this->assertFalse($component->viewData('hasMore'));

        $appended = [];
        $component->assertDispatched('monitor-logs-append', function ($name, $params) use (&$appended) {
            $appended = $this->summariesIn($params['html']);

            return true;
        });

        $this->assertSame(array_map(static fn (int $i) => "log {$i}", range(15, 1)), $appended);

        // Storage has run dry: loadMore() no-ops instead of re-querying/re-dispatching.
        $component->call('loadMore');
        $component->assertNotDispatched('monitor-logs-append');
    }

    public function test_auto_refresh_tops_up_new_entries_without_reloading_older_rows(): void
    {
        $this->recordLogs(3);

        $component = Livewire::test(Logs::class);
        $this->assertSame(['log 3', 'log 2', 'log 1'], $component->viewData('logs')->pluck('summary')->all());

        Monitor::record(RecordType::Log, 'log', ['message' => 'log 4'], null, 'error');
        Monitor::flush();

        $component->call('refreshNow');

        $prepended = [];
        $component->assertDispatched('monitor-logs-prepend', function ($name, $params) use (&$prepended) {
            $prepended = $this->summariesIn($params['html']);

            return true;
        });

        $this->assertSame(['log 4'], $prepended);
    }

    public function test_changing_the_level_filter_replaces_the_list_under_the_new_scope(): void
    {
        $this->recordLogs(3, 'error', 'error');
        $this->recordLogs(2, 'info', 'info');

        $component = Livewire::test(Logs::class);
        $component->set('level', 'info');

        $replaced = [];
        $component->assertDispatched('monitor-logs-replace', function ($name, $params) use (&$replaced) {
            $replaced = $this->summariesIn($params['html']);

            return true;
        });

        $this->assertSame(['info 2', 'info 1'], $replaced);
        $this->assertFalse($component->viewData('hasMore'));
    }
}
