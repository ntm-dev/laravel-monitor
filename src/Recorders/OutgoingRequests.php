<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;
use LaravelMonitor\Support\HttpStatusGroup;
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
            subtype: HttpStatusGroup::forStatus($status)->value,
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
            // No HTTP status to group by — kept out of the 5xx bucket (a
            // connection failure never got a response, so it isn't really a
            // server error) and grouped as its own NetworkError subtype
            // instead. payload['status'] stays null so the per-request page
            // still renders it as "Failed".
            subtype: HttpStatusGroup::NetworkError->value,
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
