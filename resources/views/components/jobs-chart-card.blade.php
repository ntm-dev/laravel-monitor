{{-- JOBS card: total + FAILED / PROCESSED / QUEUED (/ RELEASED, where tracked) legends over a stacked bar chart.
     $queued/$queuedBuckets are optional (default null): the Jobs tab's own "Attempts" chart (jobs.blade.php,
     job-detail.blade.php) omits them on purpose — a queued-but-not-yet-attempted job isn't an attempt outcome,
     only failed/processed/released are — while the Overview page's smaller Application widget still passes them,
     unchanged. --}}
@props(['queued' => null, 'processed', 'failed', 'released' => null, 'queuedBuckets' => null, 'processedBuckets', 'failedBuckets', 'releasedBuckets' => null, 'since', 'until', 'size' => 'lg', 'height' => 'h-28', 'footer' => true, 'label' => null])
@php($label ??= __('monitor::messages.nav.jobs'))
<x-monitor::card :class="trim('flex flex-col p-4 '.($attributes->get('class') ?? ''))" x-data="{ hidden: {} }">
    <x-monitor::metric :label="$label" :value="number_format(($queued ?? 0) + $processed + $failed + ($released ?? 0))" :size="$size">
        <x-monitor::legend :label="__('monitor::messages.common.failed')" dot="bg-rose-500" :value="number_format($failed)" :color="$failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-neutral-900 dark:text-neutral-100'" :size="$size" series-key="failed"/>
        @if ($released !== null)
            <x-monitor::legend :label="__('monitor::messages.common.released')" dot="bg-orange-500" :value="number_format($released)" :color="$released > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-neutral-900 dark:text-neutral-100'" :size="$size" series-key="released"/>
        @endif
        <x-monitor::legend :label="__('monitor::messages.common.processed')" dot="bg-neutral-300 dark:bg-neutral-600" :value="number_format($processed)" :size="$size" series-key="processed"/>
        @if ($queued !== null)
            <x-monitor::legend :label="__('monitor::messages.common.queued')" dot="bg-amber-500" :value="number_format($queued)" :size="$size" series-key="queued"/>
        @endif
    </x-monitor::metric>
    <div class="{{ $size === 'lg' ? 'mt-5' : 'mt-4' }}">
        <x-monitor::bar-chart :since="$since" :until="$until" :height="$height" :series="[
            ['key' => 'processed', 'label' => __('monitor::messages.common.processed'), 'dot' => 'bg-neutral-300 dark:bg-neutral-600', 'data' => $processedBuckets],
            ...($queuedBuckets !== null ? [['key' => 'queued', 'label' => __('monitor::messages.common.queued'), 'dot' => 'bg-amber-500', 'data' => $queuedBuckets]] : []),
            ...($releasedBuckets !== null ? [['key' => 'released', 'label' => __('monitor::messages.common.released'), 'dot' => 'bg-orange-500', 'data' => $releasedBuckets]] : []),
            ['key' => 'failed', 'label' => __('monitor::messages.common.failed'), 'dot' => 'bg-rose-500', 'data' => $failedBuckets],
        ]"/>
    </div>
    @if ($footer)
        <x-monitor::chart-footer :since="$since" :until="$until"/>
    @endif
</x-monitor::card>
