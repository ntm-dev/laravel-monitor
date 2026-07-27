<?php

namespace LaravelMonitor\Support;

/**
 * Stable, opaque path-segment id for a grouping key (a route signature, job/
 * command/mailable FQCN, normalized SQL shape, ...) — same shape as
 * Fingerprint's exception hash, but generic. One-way: turning a hash back
 * into its raw key means matching it against known keys, see
 * Contracts\Storage::resolveKeyHash().
 */
class KeyHash
{
    public static function for(string $key): string
    {
        return substr(sha1($key), 0, 32);
    }
}
