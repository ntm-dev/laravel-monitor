@php
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Number;

    $tz = Format::timezone();

    if ($entry !== null) {
        $payload = $entry->payload;
        $status = $payload['status'] ?? null;

        // Start of the call: created_at is stamped when the response arrived.
        $endEpoch = (float) $entry->created_at->format('U.u');
        $startEpoch = $endEpoch - ((float) $entry->duration / 1000);
        $preciseTimestamp = fn (float $epoch) => Format::datetime(
            \Carbon\CarbonImmutable::createFromFormat('U.u', number_format($epoch, 6, '.', '')),
            Format::DATETIME_PRECISE,
        ).' '.$tz;

        $general = array_filter([
            'date' => $preciseTimestamp($startEpoch),
            'method' => $payload['method'] ?? '—',
            'url' => $payload['url'] ?? $entry->key,
            'status_code' => $status ?? __('monitor::messages.common.failed'),
            'duration' => $entry->duration !== null ? Format::duration($entry->duration) : '—',
            'server' => $payload['server'] ?? null,
            'request_size' => isset($payload['request_size']) ? Number::fileSize($payload['request_size'], '—') : null,
            'response_size' => isset($payload['response_size']) ? Number::fileSize($payload['response_size'], '—') : null,
        ], fn ($value) => $value !== null);

        $generalLabels = [
            'date' => __('monitor::messages.common.date'),
            'method' => __('monitor::messages.common.method'),
            'url' => __('monitor::messages.common.url'),
            'status_code' => __('monitor::messages.common.status_code'),
            'duration' => __('monitor::messages.common.duration'),
            'server' => __('monitor::messages.common.server'),
            'request_size' => __('monitor::messages.common.request_size'),
            'response_size' => __('monitor::messages.common.response_size'),
        ];

        // Bodies are stored as (redacted) JSON text; a complete one is shown
        // as a tree, anything cut off or non-JSON stays as plain text.
        $decodeBody = function (?string $body) {
            if ($body === null) {
                return null;
            }

            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : $body;
        };
    }
@endphp
<div class="space-y-4">
    @if ($entry === null)
        <x-monitor::empty-state :label="__('monitor::messages.common.outgoing_request')" :message="__('monitor::messages.common.outgoing_request_not_found')" :period-phrase="$periodPhrase"/>
    @else
        {{-- start card general info --}}
        <x-monitor::card class="p-4">
            <h2 class="mb-3 font-semibold text-neutral-900 dark:text-neutral-100">{{ __('monitor::messages.common.general') }}</h2>
            <dl class="space-y-2 text-sm">
                @foreach ($general as $key => $value)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $generalLabels[$key] }}</dt>
                        <div class="h-0 min-w-6 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                        @if ($key === 'status_code' && is_numeric($value))
                            <dd class="shrink-0">
                                <span class="rounded px-1.5 py-0.5 font-mono text-xs {{ Format::statusBadgeClass((int) $value) }}"
                                      data-tooltip="{{ Format::statusText((int) $value) }}">{{ $value }}</span>
                            </dd>
                        @elseif ($key === 'status_code')
                            <dd class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $value }}</dd>
                        @elseif ($key === 'url')
                            <dd class="min-w-0 max-w-[70%] break-all text-right font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                        @elseif ($key === 'method')
                            <dd class="shrink-0 font-mono text-xs uppercase text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                        @else
                            <dd class="shrink-0 font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        </x-monitor::card>
        {{-- end card general info --}}

        <x-monitor::requests.message-section
            :title="__('monitor::messages.common.request')"
            :open="true"
            :headers="$payload['request_headers'] ?? []"
            :body="$decodeBody($payload['request_body'] ?? null)"
            :size="$payload['request_size'] ?? null"
        />

        @if ($status !== null)
            <x-monitor::requests.message-section
                :title="__('monitor::messages.common.response')"
                :open="true"
                :headers="$payload['response_headers'] ?? []"
                :body="$decodeBody($payload['response_body'] ?? null)"
                :size="$payload['response_size'] ?? null"
            />
        @endif
    @endif
</div>
