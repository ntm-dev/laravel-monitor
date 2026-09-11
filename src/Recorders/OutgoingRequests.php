<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;
use LaravelMonitor\Support\HttpStatusGroup;
use LaravelMonitor\Support\RecordType;
use LaravelMonitor\Support\Trace;
use Throwable;

use function array_filter;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_url;
use function strtolower;

class OutgoingRequests extends Recorder
{
    /** Body field names (case-insensitive) replaced before storing — mirrors Recorders\Requests::REDACT_BODY_FIELDS. */
    protected const REDACT_BODY_FIELDS = [
        'password', 'password_confirmation', 'token', 'secret',
        'api_key', 'apikey', 'access_token', 'refresh_token',
    ];

    /** Stored bodies larger than this (chars) are truncated. */
    protected const MAX_BODY_CHARS = 10000;

    public function register(Dispatcher $events): void
    {
        $events->listen(ResponseReceived::class, [$this, 'recordResponse']);
        $events->listen(ConnectionFailed::class, [$this, 'recordFailure']);
    }

    public function recordResponse(ResponseReceived $event): void
    {
        $status = $event->response->status();
        $recordBody = $this->config['details']['record_body'] ?? false;

        $this->monitor->record(
            type: RecordType::OutgoingRequest,
            key: $this->key($event->request->url()),
            payload: array_filter([
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => $status,
                'request_body' => $recordBody ? $this->body($event->request->body()) : null,
                'response_body' => $recordBody ? $this->body($event->response->body()) : null,
                'trace' => ($this->config['details']['trace'] ?? false) ? Trace::capture() : null,
            ], fn ($value) => $value !== null),
            duration: $this->duration($event),
            subtype: HttpStatusGroup::forStatus($status)->value,
        );
    }

    public function recordFailure(ConnectionFailed $event): void
    {
        $this->monitor->record(
            type: RecordType::OutgoingRequest,
            key: $this->key($event->request->url()),
            payload: array_filter([
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => null,
                'request_body' => ($this->config['details']['record_body'] ?? false) ? $this->body($event->request->body()) : null,
                'trace' => ($this->config['details']['trace'] ?? false) ? Trace::capture() : null,
            ], fn ($value) => $value !== null),
            // No HTTP status to group by — kept out of the 5xx bucket (a
            // connection failure never got a response, so it isn't really a
            // server error) and grouped as its own NetworkError subtype
            // instead. payload['status'] stays null so the per-request page
            // still renders it as "Failed".
            subtype: HttpStatusGroup::NetworkError->value,
        );
    }

    /**
     * Best-effort body: redacted field-by-field when it's JSON, capped in
     * size either way. Never thrown from — a body that fails to decode
     * just falls back to the raw (capped) string.
     */
    protected function body(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $encoded = json_encode($this->redactBody($decoded));
            $raw = is_string($encoded) ? $encoded : $raw;
        }

        return Str::limit($raw, self::MAX_BODY_CHARS);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    protected function redactBody(array $input): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            $result[$key] = match (true) {
                is_array($value) => $this->redactBody($value),
                in_array(strtolower((string) $key), self::REDACT_BODY_FIELDS, true) => '••• redacted •••',
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Grouping key for the outgoing-requests list/detail pages: the
     * destination host, not the full method+path — see Livewire\OutgoingRequests.
     */
    protected function key(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?? $url;
    }

    protected function duration(ResponseReceived $event): ?float
    {
        try {
            $stats = $event->response->transferStats;

            return $stats?->getTransferTime() !== null
                ? $stats->getTransferTime() * 1000
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
