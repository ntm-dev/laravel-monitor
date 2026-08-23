<?php

namespace LaravelMonitor\Support;

/**
 * The `monitor_entries.subtype` values Recorders\Requests and
 * Recorders\OutgoingRequests record an HTTP-status-bucketed entry under, and
 * every read-side query (DatabaseStorage::routeStats()/countsPerBucket()/
 * statsBySubtype(), the Requests/Outgoing Livewire cards) groups by.
 *
 * NetworkError has no underlying HTTP status — a connection that never got a
 * response (Recorders\OutgoingRequests::recordFailure()) — so it's kept out
 * of ServerError rather than aliased onto it, or a real 5xx and an
 * unreachable host would be indistinguishable everywhere subtype is grouped.
 */
enum HttpStatusGroup: string
{
    case Informational = '1xx';
    case Successful = '2xx';
    case Redirection = '3xx';
    case ClientError = '4xx';
    case ServerError = '5xx';
    case NetworkError = 'net_err';

    public static function forStatus(int $status): self
    {
        return match (true) {
            $status >= 500 => self::ServerError,
            $status >= 400 => self::ClientError,
            $status >= 300 => self::Redirection,
            default => self::Successful,
        };
    }
}
