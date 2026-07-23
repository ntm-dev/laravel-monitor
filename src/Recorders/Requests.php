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
     * Route-list label for requests that matched no Laravel route.
     */
    public const UNMATCHED_ROUTE = 'Unmatched Route';

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

    /**
     * Body field names (case-insensitive, any nesting depth) replaced before
     * storing. Mirrors REDACT_HEADERS' intent for the request body.
     */
    protected const REDACT_BODY_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
    ];

    /** Stored bodies larger than this (encoded, in bytes) are replaced with a size marker instead. */
    protected const MAX_BODY_BYTES = 10000;

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
        // Requests with no matched Laravel route (404s, arbitrary probed
        // paths) are grouped under one label instead of the raw path, or
        // dynamic/unknown URLs would each fragment the route list into
        // their own row. Mirrors Nightwatch's "Unmatched Route" grouping.
        $uri = $route && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : self::UNMATCHED_ROUTE;

        // LARAVEL_START before REQUEST_TIME_FLOAT — see Monitor::beginRequest() for why.
        $startTime = \defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

        try {
            $userId = $request->user()?->getAuthIdentifier();
        } catch (Throwable) {
            $userId = null;
        }

        $this->monitor->record(
            type: 'request',
            key: $request->method().' '.$uri,
            payload: array_filter([
                'method' => $request->method(),
                'path' => '/'.ltrim($path, '/'),
                'url' => $this->url($request),
                'status' => $status,
                'ip' => $request->ip(),
                'server' => gethostname() ?: null,
                'route_name' => $route?->getName(),
                'route_action' => $route ? $this->routeAction($route) : null,
                'route_domain' => $route?->getDomain(),
                'request_size' => strlen($request->getContent()),
                'response_size' => $this->responseSize($event->response),
                'peak_memory' => memory_get_peak_usage(true),
                'request_headers' => $this->headers($request->headers),
                'response_headers' => $this->headers($event->response->headers),
                'body' => $this->body($request),
            ], fn ($value) => $value !== null),
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

    /**
     * "Controller@method" for a route backed by a controller, the route's
     * declared action name otherwise (e.g. "Closure") — whatever
     * getActionName() already resolves, since Laravel formats both cases
     * consistently.
     */
    protected function routeAction(mixed $route): ?string
    {
        return method_exists($route, 'getActionName') ? $route->getActionName() : null;
    }

    /**
     * Best-effort request body, redacted and capped in size — skipped for
     * GET/HEAD (query params already show up in the URL, and Laravel has no
     * concept of a GET body worth capturing separately). Mirrors Nightwatch,
     * which applies the same method restriction.
     */
    protected function body(Request $request): ?array
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $input = $request->isJson() ? (array) $request->json()->all() : $request->request->all();

        if ($input === []) {
            return null;
        }

        $redacted = $this->redactBody($input);
        $encoded = json_encode($redacted);

        if (! is_string($encoded) || strlen($encoded) > self::MAX_BODY_BYTES) {
            return ['_truncated' => true, '_size' => is_string($encoded) ? strlen($encoded) : null];
        }

        return $redacted;
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
                is_scalar($value) || $value === null => $value,
                // File uploads and other non-scalar values aren't meaningful
                // to store as-is; note the type instead of failing to encode.
                default => '('.get_debug_type($value).')',
            };
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
