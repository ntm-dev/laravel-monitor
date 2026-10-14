<?php

namespace LaravelMonitor\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Support\JsonTree;
use LaravelMonitor\Support\RecordType;

use function str_replace;

/**
 * HTML fragments for the Request Detail page's exceptions and logs cards,
 * fetched after the page itself has loaded (see
 * components/requests/deferred-card.blade.php). An empty body (204) means
 * the request has none, so the page keeps the card hidden.
 */
class RequestEntriesController
{
    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function exceptions(string $requestId): Response
    {
        $exceptions = $this->storage->entriesOfTypeFor($requestId, RecordType::Exception->value);

        if ($exceptions->isEmpty()) {
            return response()->noContent();
        }

        return response(view('monitor::components.requests.exception-list', ['exceptions' => $exceptions])->render());
    }

    public function logs(string $requestId): Response
    {
        $logs = $this->storage->entriesOfTypeFor($requestId, RecordType::Log->value);

        if ($logs->isEmpty()) {
            return response()->noContent();
        }

        return response(view('monitor::components.requests.log-list', ['logs' => $this->presentLogs($logs)])->render());
    }

    /**
     * Adds the fields livewire/logs-entry.blade.php reads, minus the source
     * link (every row here belongs to the same request).
     */
    protected function presentLogs(Collection $logs): Collection
    {
        return $logs->map(static function (object $log): object {
            $contextRaw = $log->payload['context'] ?? '{}';
            $message = $log->payload['message'] ?? '';

            $log->level = $log->subtype ?? 'info';
            $log->contextRaw = $contextRaw;
            $log->contextTree = JsonTree::parse($contextRaw);
            $log->summary = $message !== ''
                ? $message
                : Str::limit(str_replace(["\r\n", "\n", "\r"], ' ', $contextRaw), 200);
            $log->sourceUrl = null;

            return $log;
        });
    }
}
