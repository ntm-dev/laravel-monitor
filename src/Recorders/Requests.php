<?php

namespace LaravelMonitor\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use LaravelMonitor\Support\HttpStatusGroup;
use LaravelMonitor\Support\Json;
use LaravelMonitor\Support\RecordType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function array_filter;
use function defined;
use function get_debug_type;
use function gethostname;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_last_error_msg;
use function ltrim;
use function mb_check_encoding;
use function mb_strcut;
use function memory_get_peak_usage;
use function method_exists;
use function microtime;
use function round;
use function strlen;
use function strtolower;

class Requests extends Recorder
{
    /**
     * Route-list label for requests that matched no Laravel route.
     */
    public const UNMATCHED_ROUTE = 'Unmatched Route';

    /**
     * Headers whose values are replaced before storing (lowercase), so
     * secrets/session tokens never land in the stored payload.
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
    protected const MAX_BODY_BYTES = 64 * 1024;

    public function register(Dispatcher $events): void
    {
        $events->listen(RequestHandled::class, [$this, 'record']);
    }

    public function record(RequestHandled $event): void
    {
        $request = $event->request;
        $path = $request->path();

        if ($this->shouldIgnore()) {
            return;
        }

        $status = $event->response->getStatusCode();
        $route = $request->route();
        // Requests with no matched Laravel route (404s, arbitrary probed
        // paths) are grouped under one label instead of the raw path, or
        // dynamic/unknown URLs would each fragment the route list into
        // their own row.
        $uri = $route && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : self::UNMATCHED_ROUTE;

        // LARAVEL_START before REQUEST_TIME_FLOAT — see Monitor::beginRequest() for why.
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        // round(x, 3): both operands are ~1.7-billion-magnitude Unix epoch
        // floats, so subtracting them is a floating-point catastrophic
        // cancellation — see Monitor::elapsedMsPrecise()'s own docs. 3
        // decimals matches microtime()'s own microsecond resolution.
        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 3) : null;

        $this->monitor->record(
            type: RecordType::Request,
            key: "{$request->method()} {$uri}",
            payload: array_filter([
                'request' => array_filter([
                    'method' => $request->method(),
                    'path' => '/'.ltrim($path, '/'),
                    'url' => $this->url($request),
                    'ip' => $request->ip(),
                    'route_name' => $route?->getName(),
                    'route_action' => $route ? $this->routeAction($route) : null,
                    'route_domain' => $route?->getDomain(),
                    'size' => strlen($request->getContent()),
                    'headers' => $this->headers($request->headers),
                    'body' => $this->body($request),
                ], static fn ($value) => $value !== null),
                'response' => array_filter([
                    'status' => $status,
                    'size' => $this->responseSize($event->response),
                    'headers' => $this->headers($event->response->headers),
                    'body' => ($this->config['details']['record_response_body'] ?? false)
                        ? $this->responseBody($event->response)
                        : null,
                ], static fn ($value) => $value !== null),
                'server' => gethostname() ?: null,
                'peak_memory' => memory_get_peak_usage(true),
                // Recorded here rather than reconstructed later from
                // created_at - duration (see MergesJobTimelines::buildTracks(),
                // which prefers this when present) — this is the exact
                // moment the request actually started, not an approximation.
                'started_at' => $startTime ?: null,
            ], static fn ($value) => $value !== null),
            duration: $duration,
            subtype: HttpStatusGroup::forStatus($status)->value,
            userId: $this->monitor->lazyCurrentUserId(),
        );
    }

    protected function url(Request $request): string
    {
        $query = (string) $request->server->get('QUERY_STRING');

        return $request->getSchemeAndHttpHost()
            .$request->getBaseUrl()
            .$request->getPathInfo()
            .($query !== '' ? "?{$query}" : '');
    }

    /**
     * Best-effort response size in bytes: the rendered content when
     * available, the file size for downloads, otherwise the declared
     * Content-Length (0 for undeclared streamed responses).
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
     * concept of a GET body worth capturing separately).
     *
     * @return array<array-key, mixed>|string|null
     */
    protected function body(Request $request): array|string|null
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $input = $request->isJson() ? (array) $request->json()->all() : $request->request->all();

        if ($input === []) {
            return null;
        }

        return $this->cappedJsonBody($input);
    }

    /**
     * Rendered response content: redacted array for JSON, capped string for
     * other UTF-8 text, null for binary/streamed/empty responses.
     *
     * @return array<array-key, mixed>|string|null
     */
    protected function responseBody(Response $response): array|string|null
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = Json::decode($content);

        if (is_array($decoded)) {
            return $this->cappedJsonBody($decoded);
        }

        return mb_check_encoding($content, 'UTF-8') ? $this->truncate($content) : null;
    }

    /**
     * Redacted body; once its encoded JSON exceeds MAX_BODY_BYTES, that JSON cut to size instead.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>|string
     */
    protected function cappedJsonBody(array $input): array|string
    {
        $redacted = $this->redactBody($input);
        $encoded = Json::encode($redacted);

        if (! is_string($encoded)) {
            return '('.json_last_error_msg().')';
        }

        return strlen($encoded) > self::MAX_BODY_BYTES ? $this->truncate($encoded) : $redacted;
    }

    /** Cut to MAX_BODY_BYTES on a UTF-8 character boundary, with a trailing ellipsis. */
    protected function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_BODY_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_BODY_BYTES, 'UTF-8').'…';
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

    protected function shouldIgnore(): bool
    {
        return $this->monitor->isSelfRequest($this->config['ignore_paths'] ?? []);
    }
}
