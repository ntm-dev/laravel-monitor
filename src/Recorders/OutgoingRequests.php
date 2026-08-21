<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;
use LaravelMonitor\Support\RecordType;
use Throwable;

use function parse_url;

class OutgoingRequests extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(ResponseReceived::class, [$this, 'recordResponse']);
        $events->listen(ConnectionFailed::class, [$this, 'recordFailure']);
    }

    public function recordResponse(ResponseReceived $event): void
    {
        $status = $event->response->status();

        $this->monitor->record(
            type: RecordType::OutgoingRequest,
            key: $this->key($event->request->url()),
            payload: [
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => $status,
            ],
            duration: $this->duration($event),
            subtype: $this->statusGroup($status),
        );
    }

    public function recordFailure(ConnectionFailed $event): void
    {
        $this->monitor->record(
            type: RecordType::OutgoingRequest,
            key: $this->key($event->request->url()),
            payload: [
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => null,
            ],
            // No HTTP status to group by — bucketed with the server-error
            // column (rather than a bucket of its own) since a connection
            // failure is, from the caller's perspective, that domain being
            // unreachable. payload['status'] stays null so the per-request
            // page still renders it as "Failed", not a fake 5xx.
            subtype: '5xx',
        );
    }

    /**
     * Grouping key for the outgoing-requests list/detail pages: the
     * destination host, not the full method+path — see Livewire\OutgoingRequests.
     */
    protected function key(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?? $url;
    }

    protected function statusGroup(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            default => '2xx',
        };
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
