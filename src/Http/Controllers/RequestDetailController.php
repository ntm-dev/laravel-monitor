<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\TimelineStorage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Livewire\Card;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;
use LaravelMonitor\Support\Trace;

use function array_key_exists;
use function is_string;
use function parse_str;
use function parse_url;
use function trim;

/**
 * Request Detail page (`monitor.requests.show`): header, general/user info,
 * event summary and the lifecycle timeline for one HTTP request.
 */
class RequestDetailController
{
    use MergesJobTimelines;
    use ResolvesUserNames;

    /**
     * Recorder type => events-summary bucket key.
     */
    protected const SUMMARY_TYPES = [
        'query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'job' => 'jobs',
        'outgoing_request' => 'outgoing',
        'lazy_loading' => 'lazy_loading',
    ];

    /**
     * {@see SUMMARY_TYPES} minus 'job', for a job track's info: a job doesn't summarise itself.
     */
    protected const SUMMARY_TYPES_JOB = [
        'query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'outgoing_request' => 'outgoing',
        'lazy_loading' => 'lazy_loading',
    ];

    public function __construct(protected TimelineStorage $storage)
    {
    }

    public function __invoke(string $requestId, ?string $jobId = null): View
    {
        $root = $this->storage->findByRequestId($requestId);

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($requestId);

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'REQUEST');

        // Range for breadcrumb/back links, so going back keeps the list's period.
        $range = $this->refererRange();

        // Extra lookup, only needed when there's a job track to link from.
        $jobBaseUrl = count($tracks) > 1 ? $this->requestUrl($requestId) : null;

        // One info bundle per track id, all rendered up front so switching tracks
        // only flips Alpine's activeInfo. Jobs are prefetched in batch, not per job.
        $infos = ['root' => $this->resolveInfo($root, $children, $requestId, null, $range)];

        $outcomeIds = [];

        foreach ($tracks as $track) {
            if (isset($track['attempts'])) {
                $outcomeIds[$track['id']] = end($track['attempts'])['outcomeId'];
            }
        }

        $jobs = $this->storage->findManyByRequestId(array_values($outcomeIds), 'job');
        $jobChildren = $this->storage->timelinesFor(array_values($outcomeIds), 'job');

        foreach ($outcomeIds as $trackId => $outcomeId) {
            $job = $jobs->get($outcomeId);

            if ($job === null) {
                continue;
            }

            $infos[$trackId] = $this->resolveInfo($job, $jobChildren->get($outcomeId, collect()), $requestId, $outcomeId, $range, $jobBaseUrl);
        }

        return view('monitor::request-detail-page', [
            'infos' => $infos,
            'activeInfoId' => $this->defaultTrackId($tracks, $jobId),
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'scrollToOutcomeId' => $jobId,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'range' => $range,
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => \LaravelMonitor\Support\Format::timezone(),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
            'jobBaseUrl' => $jobBaseUrl,
        ]);
    }

    /**
     * Top-section data for the request ($job_id === null) or one job; $children are $root's timeline rows.
     *
     * @return array{root: object, isJob: bool, queuedAt: ?CarbonImmutable, queuedFrom: ?string, summary: array, userName: ?string, tab: string, breadcrumbLabel: ?string, breadcrumbUrl: ?string, url: string, title: string}
     */
    protected function resolveInfo(object $root, Collection $children, string $requestId, ?string $job_id, array $range, ?string $jobBaseUrl = null): array
    {
        $isJob = $job_id !== null;

        $userName = ! $isJob && $root->user_id !== null
            ? ($this->resolveNames([$root->user_id])[$root->user_id] ?? null)
            : null;

        $queuedAt = null;
        // Dispatch location; only the 'queued' entry records it.
        $queuedFrom = null;

        if ($isJob && ($jobDispatchId = $root->payload['job_id'] ?? null) !== null) {
            $queuedEntry = $this->storage->findQueuedJobByJobId($jobDispatchId, CarbonImmutable::now()->subDays(30));
            $queuedAt = $queuedEntry?->created_at;
            $queuedFrom = $queuedEntry?->payload['location'] ?? Trace::location($queuedEntry?->payload['trace'] ?? null, absolute: true);
        }

        $summary = $isJob
            ? $this->eventsSummary($children, self::SUMMARY_TYPES_JOB)
            : $this->eventsSummary($children, self::SUMMARY_TYPES, $root);

        // Breadcrumb middle segment: the job class or the request's route, linking to its grouped list.
        if ($isJob) {
            $breadcrumbLabel = $root->key;
            $breadcrumbUrl = route('monitor.jobs.show', ['hash' => KeyHash::for($root->key)] + $range);
        } else {
            $routeKey = $this->storage->rootLabelsFor([$requestId])->get($requestId);
            $breadcrumbLabel = $routeKey !== null ? Str::after($routeKey, ' ') : null;
            $breadcrumbUrl = $routeKey !== null
                ? route('monitor.requests.routes.show', ['hash' => KeyHash::for($routeKey)] + $range)
                : null;
        }

        $method = $root->payload['request']['method'] ?? '';
        $path = $root->payload['request']['path'] ?? $root->key ?? '';

        return [
            'root' => $root,
            'isJob' => $isJob,
            'queuedAt' => $queuedAt,
            'queuedFrom' => $queuedFrom,
            'summary' => $summary,
            'userName' => $userName,
            'tab' => $isJob ? 'jobs' : 'requests',
            'breadcrumbLabel' => $breadcrumbLabel,
            'breadcrumbUrl' => $breadcrumbUrl,
            // Job: base url + outcome id, as the timeline links it; request: bare url.
            'url' => $isJob && $jobBaseUrl !== null ? "{$jobBaseUrl}/{$job_id}" : $this->requestUrl($requestId),
            'title' => $isJob ? class_basename($root->key ?? 'Job') : trim("{$method} {$path}"),
        ];
    }

    /**
     * @param  array<string, string>  $types
     * @return array<string, array{count: int, duration: float}>
     */
    protected function eventsSummary(Collection $children, array $types, ?object $root = null): array
    {
        $summary = collect($types)
            ->flip()
            ->map(fn () => ['count' => 0, 'duration' => 0])
            ->all();

        foreach ($children as $row) {
            $key = $types[$row->type] ?? null;

            if ($key === null) {
                continue;
            }

            $summary[$key]['count']++;
            $summary[$key]['duration'] += (float) ($row->duration ?? 0);
        }

        if (! isset($summary['queries'])) {
            return $summary;
        }

        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'query'));

        // Prefer the request's own query_count; the row count is the fallback.
        if ($root !== null && isset($root->payload['query_count'])) {
            $summary['queries']['count'] = (int) $root->payload['query_count'];
        }

        return $summary;
    }

    /**
     * Route-hashed request URL when its key is known, else the plain one.
     * Also the base job-track links append an outcome id to.
     */
    protected function requestUrl(string $requestId): string
    {
        $key = $this->storage->rootLabelsFor([$requestId])->get($requestId);

        if ($key === null) {
            return route('monitor.requests.show', $requestId);
        }

        return route('monitor.requests.routes.request', ['hash' => KeyHash::for($key), 'requestId' => $requestId]);
    }

    /**
     * period/from/to from the Referer's query string (this page's url carries none);
     * Card::DEFAULT_PERIOD when absent or invalid.
     *
     * @return array<string, string>
     */
    protected function refererRange(): array
    {
        $refererQuery = [];

        if (($referer = request()->header('referer')) !== null) {
            parse_str((string) parse_url($referer, PHP_URL_QUERY), $refererQuery);
        }

        // parse_str() yields arrays for `period[]=`; is_string() avoids a TypeError.
        $period = $refererQuery['period'] ?? null;
        $period = is_string($period) && array_key_exists($period, Card::periods()) ? $period : Card::DEFAULT_PERIOD;

        $from = $refererQuery['from'] ?? null;
        $to = $refererQuery['to'] ?? null;
        [$from, $to] = Card::normalizeRange(is_string($from) ? $from : null, is_string($to) ? $to : null);

        return array_filter(['period' => $period, 'from' => $from, 'to' => $to]);
    }
}
