<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

class Requests extends Recorder
{
    public function register(Dispatcher $events): void
    {
        $events->listen(RequestHandled::class, [$this, 'record']);
    }

    public function record(RequestHandled $event): void
    {
        $request = $event->request;
        $path = $request->path();

        if ($this->shouldIgnore($path)) {
            return;
        }

        $status = $event->response->getStatusCode();
        $route = $request->route();
        $uri = $route && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($path, '/');

        $startTime = $request->server('REQUEST_TIME_FLOAT') ?: (defined('LARAVEL_START') ? LARAVEL_START : null);
        $duration = $startTime ? (int) round((microtime(true) - $startTime) * 1000) : null;

        try {
            $userId = $request->user()?->getAuthIdentifier();
        } catch (Throwable) {
            $userId = null;
        }

        $this->monitor->record(
            type: 'request',
            key: $request->method().' '.$uri,
            payload: [
                'method' => $request->method(),
                'path' => '/'.ltrim($path, '/'),
                'status' => $status,
                'ip' => $request->ip(),
            ],
            duration: $duration,
            subtype: $this->statusGroup($status),
            userId: $userId,
        );
    }

    protected function shouldIgnore(string $path): bool
    {
        $patterns = array_merge(
            $this->config['ignore_paths'] ?? [],
            [trim(config('monitor.path', 'monitor'), '/').'*'],
        );

        return $this->matchesAny($path, $patterns);
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
}
