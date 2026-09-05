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

        // Carried through every breadcrumb/back link on this page, so
        // navigating here from a period-scoped Requests list (?period=24h)
        // and then back out lands on that same period, not the default.
        // Deliberately not read from *this* page's own query string — this
        // page's own url stays period-free — but recovered from the
        // Referer header the browser sent getting here instead.
        $range = $this->refererRange();

        // Only worth resolving (an extra lookup) when there's at least one
        // job track to actually link from — see Timeline's own
        // $jobBaseUrl/$jobUrl.
        $jobBaseUrl = count($tracks) > 1 ? $this->requestUrl($requestId) : null;

        // One "info" bundle per track this page can show at the top (see
        // resolveInfo()) -- the request's own (always first, id 'root') and
        // one per resolved job track, keyed the same way MergesJobTimelines
        // already keys $tracks itself. Rendered into the page all at once
        // (request-detail-page.blade.php), each behind its own
        // x-show="activeInfo === '<id>'" — clicking a track's own row in
        // the timeline (timeline-row.blade.php) just flips that one Alpine
        // property instead of navigating here again for a root the browser
        // already has every byte of, keeping the URL bar/breadcrumb/active
        // nav tab in sync via history.pushState rather than a real request.
        $infos = ['root' => $this->resolveInfo($root, $children, $requestId, null, $range)];

        foreach ($tracks as $track) {
            if (! isset($track['attempts'])) {
                continue;
            }

            $latestOutcomeId = end($track['attempts'])['outcomeId'];
            $job = $this->storage->findByRequestId($latestOutcomeId, 'job');

            if ($job === null) {
                continue;
            }

            $infos[$track['id']] = $this->resolveInfo($job, null, $requestId, $latestOutcomeId, $range, $jobBaseUrl);
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
     * One bundle of everything the page's own top section (breadcrumb,
     * header, General/User card, Headers/Body, Event Summary, active nav
     * tab, url/title) needs to render either the request's own info
     * ($job === null) or one specific job's (see __invoke()'s own $infos).
     *
     * @return array{root: object, isJob: bool, queuedAt: ?CarbonImmutable, summary: array, userName: ?string, tab: string, breadcrumbLabel: ?string, breadcrumbUrl: ?string, url: string, title: string}
     */
    protected function resolveInfo(object $root, ?Collection $children, string $requestId, ?string $job_id, array $range, ?string $jobBaseUrl = null): array
    {
        $job = $children === null ? $root : null;
        $isJob = $job !== null;

        $userName = ! $isJob && $root->user_id !== null
            ? ($this->resolveNames([$root->user_id])[$root->user_id] ?? null)
            : null;

        $queuedAt = null;

        if ($isJob && ($jobDispatchId = $root->payload['job_id'] ?? null) !== null) {
            $queuedEntry = $this->storage->findQueuedJobByJobId($jobDispatchId, CarbonImmutable::now()->subDays(30));
            $queuedAt = $queuedEntry?->created_at;
        }

        $summary = $isJob
            ? $this->eventsSummary($this->storage->timelineFor($root->request_id, 'job'), self::SUMMARY_TYPES_JOB)
            : $this->eventsSummary($children, self::SUMMARY_TYPES, $root);

        // Breadcrumb's middle segment: this request's own route-group label
        // ("METHOD /uri"), linking back to that route's own grouped list —
        // or, for a job, that job's own class name, linking back to *its*
        // grouped list on the Jobs tab instead, matching the General/Events/
        // tab switch alongside it.
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

        return [
            'root' => $root,
            'isJob' => $isJob,
            'queuedAt' => $queuedAt,
            'summary' => $summary,
            'userName' => $userName,
            'tab' => $isJob ? 'jobs' : 'requests',
            'breadcrumbLabel' => $breadcrumbLabel,
            'breadcrumbUrl' => $breadcrumbUrl,
            // Same base url a job track's own row already appends its
            // latest attempt's outcome id onto (see View\Components\Requests\Timeline)
            // — bare for the request's own info, so clicking back to it
            // from a job's drops the trailing job id entirely.
            'url' => $isJob && $jobBaseUrl !== null ? "{$jobBaseUrl}/{$job_id}" : $this->requestUrl($requestId),
            'title' => $isJob ? class_basename($root->key ?? 'Job') : trim(($root->payload['method'] ?? '').' '.($root->payload['path'] ?? $root->key ?? '')),
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
