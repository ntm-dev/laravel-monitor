<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Livewire\Concerns\SyncsOpenIssues;
use Livewire\Attributes\On;

/**
 * Sidebar "open issues" count — a Livewire component (not a plain Blade
 * prop like the rest of navigation.blade.php) specifically so it keeps
 * polling and catches up after resolving/ignoring issues on the Issues
 * page itself: the sidebar sits outside that page's Livewire component,
 * so a static prop computed once by DashboardController would otherwise
 * keep showing whatever count was open at the last full page load.
 */
class OpenIssueBadge extends Card
{
    use SyncsOpenIssues;

    /**
     * Issues::data() dispatches this on every render (explicit resolve/
     * ignore/reopen, or its own wire:poll tick) — without it this badge
     * would only catch up on its own next wire:poll (up to
     * config('monitor.refresh') seconds later), visibly disagreeing with
     * the Issues page's own "Open" count in the meantime.
     */
    #[On('issues-changed')]
    public function refresh(): void
    {
        //
    }

    protected function view(): string
    {
        return 'monitor::livewire.open-issue-badge';
    }

    protected function data(): array
    {
        $storage = $this->storage();

        // syncIssues() (called from here via syncOpenIssues()) is the only
        // thing that writes a new/recurring issue into monitor_issues — it
        // otherwise only runs from the Issues page's own render. Without
        // this, a new exception/slow query never reaches openIssueCount()
        // until someone happens to have the Issues page open somewhere,
        // so this badge would sit stale on every other tab indefinitely.
        $this->syncOpenIssues($storage, $this->since(), $this->until());

        return [
            'count' => $storage->openIssueCount(),
        ];
    }
}
