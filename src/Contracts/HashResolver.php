<?php

namespace LaravelMonitor\Contracts;

/**
 * Maps a one-way KeyHash::for() hash back to the stored key/user_id it came from,
 * for the hashed detail-page routes.
 */
interface HashResolver
{
    /**
     * The key of $type whose hash matches, or null for a stale/invalid hash.
     */
    public function resolveKeyHash(string $type, string $hash): ?string;

    /**
     * The user_id (across all types) whose hash matches, or null for a stale/invalid hash.
     */
    public function resolveUserIdHash(string $hash): ?string;
}
