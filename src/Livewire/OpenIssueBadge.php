<?php

namespace LaravelMonitor\Livewire;

use Livewire\Attributes\On;

/**
 * Sidebar "open issues" count — a Livewire component (not a plain Blade
 * prop like the rest of navigation.blade.php) specifically so it keeps
 * polling and catches up after resolving/ignoring issues on the Issues
 * page itself: the sidebar sits outside that page's Livewire component,
 * so a static prop computed once by DashboardController would otherwise
 * keep showing whatever count was open at the last full page load.
 *
 * Reads openIssueCount() only — it does NOT call syncOpenIssues() itself.
 * That discovery pass (aggregateByKey() across every issue-tracked type,
 * each sampling up to groupLimit() rows) is too expensive to repeat on every
 * poll tick, on every open tab, sitewide (measured well over a second total
 * against this package's own production-scale table). Only the Issues page
 * itself still runs it, so a new/recurring issue reaches this badge once
 * someone has that page open somewhere — same tradeoff already documented
 * on Livewire\Concerns\SyncsOpenIssues.
 */
class OpenIssueBadge extends Card
{
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
        return [
            'count' => $this->issueStorage()->openIssueCount(),
        ];
    }
}
