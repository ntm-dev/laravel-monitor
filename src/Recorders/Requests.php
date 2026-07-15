<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Requests extends Recorder
{
    /**
     * Headers whose values are replaced before storing (lowercase).
     * Mirrors Nightwatch's header redaction.
     */
    protected const REDACT_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'php-auth-user',
        'php-auth-pw',
        'php-auth-digest',
    ];

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
        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

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
                'url' => $this->url($request),
                'status' => $status,
                'ip' => $request->ip(),
                'server' => gethostname() ?: null,
                'request_size' => strlen($request->getContent()),
                'response_size' => $this->responseSize($event->response),
                'peak_memory' => memory_get_peak_usage(true),
                'request_headers' => $this->headers($request->headers),
                'response_headers' => $this->headers($event->response->headers),
            ],
            duration: $duration,
            subtype: $this->statusGroup($status),
            userId: $userId,
        );
    }

    protected function url(Request $request): string
    {
        $query = (string) $request->server->get('QUERY_STRING');

        return $request->getSchemeAndHttpHost()
            .$request->getBaseUrl()
            .$request->getPathInfo()
            .($query !== '' ? '?'.$query : '');
    }

    /**
     * Best-effort response size in bytes, following Nightwatch: the rendered
     * content when available, the file size for downloads, otherwise the
     * declared Content-Length (0 for undeclared streamed responses).
     */
    protected function responseSize(Response $response): int
    {
        if (is_string($content = $response->getContent())) {
            return strlen($content);
        }

        if ($response instanceof BinaryFileResponse) {
            try {
                if (is_int($size = $response->getFile()->getSize())) {
                    return $size;
                }
            } catch (Throwable) {
                //
            }
        }

        if (is_numeric($length = $response->headers->get('content-length'))) {
            return (int) $length;
        }

        return 0;
    }

    /**
     * Flatten headers to name => value with sensitive values redacted.
     *
     * @return array<string, string>
     */
    protected function headers(HeaderBag $headers): array
    {
        $result = [];

        foreach ($headers->all() as $name => $values) {
            $result[$name] = in_array(strtolower($name), self::REDACT_HEADERS, true)
                ? '••• redacted •••'
                : implode(', ', $values);
        }

        return $result;
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
