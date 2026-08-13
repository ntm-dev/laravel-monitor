<?php

namespace LaravelMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelMonitor\Contracts\Storage;
use LaravelMonitor\Http\Controllers\Concerns\MergesJobTimelines;
use LaravelMonitor\Livewire\Card;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Support\KeyHash;
use LaravelMonitor\Support\Nav;
use LaravelMonitor\Support\Sql;

use function array_key_exists;
use function is_string;
use function parse_str;
use function parse_url;

/**
 * Renders the standalone Request Detail page for a single HTTP request:
 * header, general/user info, event summary and the lifecycle timeline.
 * Unlike the tab-based dashboard views, this page owns its own route
 * (`monitor.requests.show`) and fetches everything it needs itself.
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
     * Same as {@see SUMMARY_TYPES}, minus 'job' — once a job is the page's
     * active entity, its own summary is scoped to just that job's own
     * children (see eventsSummary()'s $children param), which shouldn't
     * summarise itself the same way JobAttemptController's standalone page
     * doesn't either.
     */
    protected const SUMMARY_TYPES_JOB = [
        'query' => 'queries',
        'cache' => 'cache',
        'mail' => 'mail',
        'notification' => 'notifications',
        'outgoing_request' => 'outgoing',
        'lazy_loading' => 'lazy_loading',
    ];

    public function __construct(protected Storage $storage)
    {
    }

    public function __invoke(string $requestId, ?string $jobId = null): View
    {
        $root = $this->storage->findByRequestId($requestId);

        abort_unless($root !== null, 404);

        $children = $this->storage->timelineFor($requestId);

        $userName = $root->user_id !== null
            ? ($this->resolveNames([$root->user_id])[$root->user_id] ?? null)
            : null;

        [$groups, $footerTabs] = Nav::grouped();
        $tracks = $this->buildTracks($root, $children, 'REQUEST');

        // Landed here via a job's own <request_url>/<job_id> link (see
        // JobAttemptController::ancestorUrl()) rather than the plain request
        // url — the page still renders this request's own merged timeline,
        // but the General/Headers/Body/Events sections and the active nav
        // tab switch to that job instead, since that's what the visitor
        // actually came to see.
        $job = $jobId !== null ? $this->storage->findByRequestId($jobId, 'job') : null;

        $queuedAt = null;

        if ($job !== null && ($jobDispatchId = $job->payload['job_id'] ?? null) !== null) {
            $queuedEntry = $this->storage->findQueuedJobByJobId($jobDispatchId, CarbonImmutable::now()->subDays(30));
            $queuedAt = $queuedEntry?->created_at;
        }

        $summary = $job !== null
            ? $this->eventsSummary($this->storage->timelineFor($job->request_id, 'job'), self::SUMMARY_TYPES_JOB)
            : $this->eventsSummary($children, self::SUMMARY_TYPES, $root);

        // Carried through every breadcrumb/back link on this page, so
        // navigating here from a period-scoped Requests list (?period=24h)
        // and then back out lands on that same period, not the default.
        // Deliberately not read from *this* page's own query string — this
        // page's own url stays period-free — but recovered from the
        // Referer header the browser sent getting here instead.
        $range = $this->refererRange();

        // Breadcrumb's middle segment: this request's own route-group label
        // ("METHOD /uri"), linking back to that route's own grouped list —
        // or, once a job is the page's active entity, that job's own class
        // name, linking back to *its* grouped list on the Jobs tab instead,
        // matching the General/Events/tab switch that already happens above.
        if ($job !== null) {
            $breadcrumbLabel = $job->key;
            $breadcrumbUrl = route('monitor.jobs.show', ['hash' => KeyHash::for($job->key)] + $range);
        } else {
            $routeKey = $this->storage->rootLabelsFor([$requestId])->get($requestId);
            $breadcrumbLabel = $routeKey !== null ? Str::after($routeKey, ' ') : null;
            $breadcrumbUrl = $routeKey !== null
                ? route('monitor.requests.routes.show', ['hash' => KeyHash::for($routeKey)] + $range)
                : null;
        }

        return view('monitor::request-detail-page', [
            'root' => $root,
            'job' => $job,
            'queuedAt' => $queuedAt,
            'tracks' => $tracks,
            'defaultTrack' => $this->defaultTrackId($tracks, $jobId),
            'summary' => $summary,
            'userName' => $userName,
            'groups' => $groups,
            'footerTabs' => $footerTabs,
            'tab' => $job !== null ? 'jobs' : 'requests',
            'range' => $range,
            'breadcrumbTab' => $job !== null ? 'jobs' : 'requests',
            'breadcrumbLabel' => $breadcrumbLabel,
            'breadcrumbUrl' => $breadcrumbUrl,
            'refresh' => (int) config('monitor.refresh', 10),
            'appInitial' => strtoupper(mb_substr(config('app.name', 'L'), 0, 1)),
            'timezone' => \LaravelMonitor\Support\Format::timezone(),
            'threshold' => (int) config('monitor.thresholds.request', 1000),
            // Only worth resolving (an extra lookup) when there's at least
            // one job track to actually link from — see Timeline's own
            // $jobBaseUrl/$jobUrl.
            'jobBaseUrl' => count($tracks) > 1 ? $this->requestUrl($requestId) : null,
        ]);
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

        // `query` rows only exist for queries at/above the configured
        // threshold on installs still running an older version of this
        // package's Queries recorder (it persists every query today,
        // regardless of duration — see Recorders\Queries::record()), so
        // counting them undercounts on those installs (or, as often
        // happens, shows zero for a request that ran several fast
        // queries). The request payload carries a true total incremented
        // on every query — fall back to the raw row count only for older
        // rows recorded before that counter existed.
        $summary['queries']['duplicates'] = Sql::duplicateCount($children->where('type', 'query'));

        if ($root !== null && isset($root->payload['query_count'])) {
            $summary['queries']['count'] = (int) $root->payload['query_count'];
        }

        return $summary;
    }

    /**
     * This request's own url, in whichever form the Requests tab itself
     * would link to it — hashed (grouped by its route) when its key is
     * still resolvable, falling back to the plain, non-hashed url
     * otherwise. Used as the base a job track's own row appends its
     * outcome id onto (see View\Components\Requests\Timeline).
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
     * period/from/to as they appeared on whichever page's link the visitor
     * clicked to land here, recovered from the Referer header rather than
     * this page's own query string (this page's own url stays period-free).
     * Falls back to Card's own default period when there's no Referer, it's
     * off-origin, or it carries no recognisable range at all.
     *
     * @return array<string, string>
     */
    protected function refererRange(): array
    {
        $refererQuery = [];

        if (($referer = request()->header('referer')) !== null) {
            parse_str((string) parse_url($referer, PHP_URL_QUERY), $refererQuery);
        }

        // is_string() guards: parse_str() turns a `period[]=...` referer
        // into an array, which array_key_exists()/normalizeRange() don't
        // accept — untrusted input, so fall back rather than TypeError.
        $period = $refererQuery['period'] ?? null;
        $period = is_string($period) && array_key_exists($period, Card::periods()) ? $period : Card::DEFAULT_PERIOD;

        $from = $refererQuery['from'] ?? null;
        $to = $refererQuery['to'] ?? null;
        [$from, $to] = Card::normalizeRange(is_string($from) ? $from : null, is_string($to) ? $to : null);

        return array_filter(['period' => $period, 'from' => $from, 'to' => $to]);
    }
}
