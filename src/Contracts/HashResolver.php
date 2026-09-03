<?php

namespace LaravelMonitor\Contracts;

/**
 * Reverses a KeyHash::for() hash back to the raw key/user_id it was minted
 * from, for the hashed detail-page routes (see routes/web.php) — the hash
 * itself is one-way, so this works by scanning known values for a match.
 */
interface HashResolver
{
    /**
     * The original key whose KeyHash::for() hash matches $hash, among every
     * entry of $type ever recorded — the hash is one-way, so this scans the
     * type's distinct keys (index-only via the [type, key] index) for a
     * match. Null when nothing matches (a stale/invalid hash).
     */
    public function resolveKeyHash(string $type, string $hash): ?string;

    /**
     * The original user_id whose KeyHash::for() hash matches $hash, among
     * every entry ever recorded (not scoped to one $type — a user's activity
     * spans request/job/exception/... alike) — the User Detail page's own
     * counterpart to resolveKeyHash(), since user_id is a column rather than
     * a per-type grouping key. Null when nothing matches (a stale/invalid
     * hash).
     */
    public function resolveUserIdHash(string $hash): ?string;
}
