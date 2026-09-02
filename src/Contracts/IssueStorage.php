<?php

namespace LaravelMonitor\Contracts;

use Illuminate\Support\Collection;

/**
 * The Issues page's own persistent monitor_issues rows — open/resolved/
 * ignored status and priority, synced from whatever the current period's
 * exception/performance query sees, independent of the raw entries
 * themselves (an issue survives even after its underlying entries age out).
 */
interface IssueStorage
{
    /**
     * Record that each of the given issues (an exception group or a
     * performance-threshold breach) is still occurring, as of its own last
     * occurrence in this period — creates a new "open" row on first sight,
     * otherwise just bumps last_seen. A previously "resolved" issue that
     * recurs after its resolved_at reopens automatically; an "ignored" issue
     * stays ignored until manually reopened.
     *
     * @param  array<string, \DateTimeInterface>  $lastSeenByKey
     */
    public function syncIssues(string $type, array $lastSeenByKey): void;

    /**
     * Delete every currently-"open" issue of $type whose key is absent
     * from $currentKeys — syncIssues()'s complement: syncIssues() only
     * ever opens/bumps/reopens, so without this an issue stays "open"
     * forever once nothing keeps recording it (a performance key that's
     * dropped back under its threshold, or all its underlying entries have
     * been pruned) even though openIssueCount() keeps counting it and it
     * never shows up on the page again. An "ignored"/"resolved" issue is
     * left alone — only a stuck-"open" one with nothing behind it anymore
     * gets removed. An empty $currentKeys deletes every open issue of
     * $type. Returns the number of issues deleted.
     *
     * @param  string[]  $currentKeys
     */
    public function deleteMissingIssues(string $type, array $currentKeys): int;

    /**
     * Status + priority + first_seen for each of the given keys of a type,
     * keyed by key — batches what would otherwise be one lookup per row on
     * the Issues page. A key with no matching row (not yet synced) is
     * simply absent.
     *
     * @param  string[]  $keys
     * @return Collection<string, object{id: int, uuid: string, status: string, priority: string, first_seen: \Carbon\CarbonImmutable}>
     */
    public function issueStatuses(string $type, array $keys): Collection;

    /**
     * Set an issue's status directly (open/resolved/ignored) — the resolve/
     * ignore/reopen actions on the Issues page. Creates the row if
     * syncIssues() hasn't seen this key yet rather than silently no-op-ing.
     */
    public function setIssueStatus(string $type, string $key, string $status): void;

    /**
     * Count of issues currently "open" — powers the sidebar badge. Not
     * scoped to the viewer's selected time range: issues are persistent
     * records synced by syncIssues(), not a windowed event count.
     */
    public function openIssueCount(): int;

    /**
     * Delete every "open" issue whose (type, key) no longer matches any row
     * in monitor_entries — called by PruneCommand right after it purges
     * old entries, so an issue never sits "open" forever once the raw data
     * proving it recurred is gone. Checked by actual existence rather than
     * comparing last_seen against the prune cutoff: a key can predate that
     * cutoff's data even while last_seen itself still looks recent, e.g.
     * after an earlier prune ran with a shorter --hours value. Returns the
     * number of issues deleted.
     */
    public function expireStaleIssues(): int;

    /**
     * Set an issue's priority (one of Format::PRIORITIES' keys) — silently
     * no-ops on an invalid value. Creates the row if syncIssues() hasn't
     * seen this key yet, same as setIssueStatus().
     */
    public function setIssuePriority(string $type, string $key, string $priority): void;

    /**
     * Resolve a monitor_issues row by its uuid — the /monitor/issues/{uuid}
     * detail route uses this to find the [type, key] pair to fetch the
     * underlying exception/performance data for.
     */
    public function findIssueByUuid(string $uuid): ?object;
}
