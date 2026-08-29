<?php

namespace LaravelMonitor\Livewire;

use LaravelMonitor\Support\Format;

/**
 * Status/Priority controls embedded in the standalone Issue Detail page
 * (see Http\Controllers\IssueController::show() / issue-detail-page.blade.php).
 * A Livewire component instead of the plain POST+redirect forms it replaced,
 * so changing either only re-renders this panel — the exception/performance
 * data alongside it on the page doesn't reload.
 */
class IssueManagePanel extends Card
{
    public string $uuid = '';

    public function mount(?string $period = null, ?string $from = null, ?string $to = null, ?string $uuid = null): void
    {
        parent::mount($period, $from, $to);

        $this->uuid = $uuid ?? (string) request('uuid', '');
    }

    public function setStatus(string $status): void
    {
        if (! in_array($status, Issues::STATUSES, true)) {
            return;
        }

        $issue = $this->issue();

        if ($issue === null || $issue->status === $status) {
            return;
        }

        $this->storage()->setIssueStatus($issue->type, $issue->key, $status);

        // Same nudge Issues.php's own resolve()/ignore() send, so the
        // sidebar's OpenIssueBadge count doesn't drift behind this change.
        $this->dispatch('issues-changed');

        [$level, $message] = match ($status) {
            'resolved' => ['success', trans_choice('monitor::messages.issue.toast_resolved', 1)],
            'ignored' => ['success', trans_choice('monitor::messages.issue.toast_ignored', 1)],
            default => ['info', __('monitor::messages.issue.toast_reopened')],
        };

        $this->notify($level, $message);
    }

    public function setPriority(string $priority): void
    {
        if (! array_key_exists($priority, Format::PRIORITIES)) {
            return;
        }

        $issue = $this->issue();

        if ($issue === null || $issue->priority === $priority) {
            return;
        }

        $this->storage()->setIssuePriority($issue->type, $issue->key, $priority);
        $this->notify('success', __('monitor::messages.issue.toast_priority_updated', ['level' => Format::priorityLabel($priority)]));
    }

    protected function issue(): ?object
    {
        return $this->storage()->findIssueByUuid($this->uuid);
    }

    protected function view(): string
    {
        return 'monitor::livewire.issue-manage-panel';
    }

    protected function data(): array
    {
        return [
            'issue' => $this->issue(),
            'statuses' => Issues::STATUSES,
            'priorities' => Format::priorityOptions(),
        ];
    }
}
