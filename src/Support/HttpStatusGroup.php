<?php

namespace LaravelMonitor\Support;

/**
 * `monitor_entries.subtype` status buckets for request and outgoing-request entries.
 * NetworkError means no response at all (Recorders\OutgoingRequests::recordFailure()),
 * kept apart from ServerError so an unreachable host isn't counted as a 5xx.
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
