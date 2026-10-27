<?php

namespace LaravelMonitor\Contracts;

use Illuminate\Support\Collection;

/**
 * Persistent issue state (status, priority) per [type, key], stored apart from raw entries.
 */
interface IssueStorage
{
    /**
     * Create an "open" issue per unseen key, else bump last_seen. A resolved issue seen after
     * its resolved_at reopens; an ignored one stays ignored.
     *
     * @param  array<string, \DateTimeInterface>  $lastSeenByKey
     */
    public function syncIssues(string $type, array $lastSeenByKey): void;

    /**
     * Delete "open" issues of $type whose key is not in $currentKeys (all of them when empty);
     * resolved/ignored issues are kept. Returns the number deleted.
     *
     * @param  string[]  $currentKeys
     */
    public function deleteMissingIssues(string $type, array $currentKeys): int;

    /**
     * Issue state for each given key, keyed by key; keys not yet synced are absent.
     *
     * @param  string[]  $keys
     * @return Collection<string, object{id: int, uuid: string, status: string, priority: string, first_seen: \Carbon\CarbonImmutable}>
     */
    public function issueStatuses(string $type, array $keys): Collection;

    /**
     * Set status (open/resolved/ignored; anything else is a no-op), creating the issue if unseen.
     */
    public function setIssueStatus(string $type, string $key, string $status): void;

    /**
     * Number of "open" issues, regardless of time range.
     */
    public function openIssueCount(): int;

    /**
     * Delete "open" issues with no remaining raw entry for their [type, key]
     * (run by PruneCommand after purging). Returns the number deleted.
     */
    public function expireStaleIssues(): int;

    /**
     * Set priority (a Format::PRIORITIES key; anything else is a no-op), creating the issue if unseen.
     */
    public function setIssuePriority(string $type, string $key, string $priority): void;

    /**
     * An issue (id, uuid, type, key, status, priority, first_seen, last_seen) by uuid, or null.
     */
    public function findIssueByUuid(string $uuid): ?object;
}
