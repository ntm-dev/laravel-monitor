<?php

namespace LaravelMonitor\Livewire;

use Livewire\Attributes\On;

/**
 * Sidebar open-issue count, a Livewire component so it updates without a
 * full page load. Reads openIssueCount() only: syncOpenIssues() is too
 * expensive to run on every poll.
 */
class OpenIssueBadge extends Card
{
    /**
     * Issues::data() dispatches this whenever the open count changes, so the
     * badge doesn't wait for its next poll.
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
