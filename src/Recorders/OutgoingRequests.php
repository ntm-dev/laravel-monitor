<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;
use Throwable;

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
            type: 'outgoing_request',
            key: $this->key($event->request->method(), $event->request->url()),
            payload: [
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => $status,
            ],
            duration: $this->duration($event),
            subtype: $status >= 400 ? 'error' : 'success',
        );
    }

    public function recordFailure(ConnectionFailed $event): void
    {
        $this->monitor->record(
            type: 'outgoing_request',
            key: $this->key($event->request->method(), $event->request->url()),
            payload: [
                'method' => $event->request->method(),
                'url' => Str::limit($event->request->url(), 500),
                'status' => null,
            ],
            subtype: 'failed',
        );
    }

    protected function key(string $method, string $url): string
    {
        return $method.' '.Str::before($url, '?');
    }

    protected function duration(ResponseReceived $event): ?int
    {
        try {
            $stats = $event->response->transferStats;

            return $stats?->getTransferTime() !== null
                ? (int) round($stats->getTransferTime() * 1000)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
