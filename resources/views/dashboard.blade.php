@php
    use Illuminate\Support\Str;
    use LaravelMonitor\Livewire\Card;
    use LaravelMonitor\Support\Format;
    use LaravelMonitor\Support\Icons;
    use LaravelMonitor\Support\Nav;

    $tabs = Nav::tabs();
    $key = $key ?? null;
    $from = $from ?? null;
    $to = $to ?? null;
    $hasCustomRange = filled($from) && filled($to);

    // Query-string state carried through every dashboard link.
    $range = array_filter(['period' => $period, 'from' => $from, 'to' => $to]);

    $groups = [];
    $footerTabs = [];
    foreach ($tabs as $tabKey => $item) {
        if ($item['group'] === 'footer') {
            $footerTabs[$tabKey] = $item;
        } else {
            $groups[$item['group']][$tabKey] = $item;
        }
    }

    $isDetail = in_array($tab, ['requests', 'jobs', 'exceptions'], true) && filled($key);
    $detailClass = $detailClass ?? null;

    $title = $tabs[$tab]['label'];
    $refresh = (int) config('monitor.refresh', 10);
    $appInitial = strtoupper(mb_substr(config('app.name', 'L'), 0, 1));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isDetail ? ($detailClass ? class_basename($detailClass) : $key) : $title }} — Monitor</title>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['InterVariable', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"CommitMono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
                    },
                },
            },
        };
    </script>
    <style>[x-cloak] { display: none !important; }</style>
    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-50 font-sans text-neutral-900 antialiased">
    <div class="flex min-h-screen">

        <aside class="sticky top-0 hidden h-screen w-[228px] shrink-0 flex-col border-r border-neutral-200 bg-white md:flex">
            <div class="p-2">
                <div class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-neutral-900 text-sm font-semibold text-white">{{ $appInitial }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold leading-tight">{{ config('app.name', 'Laravel') }}</span>
                        <span class="block text-xs leading-tight text-neutral-500">{{ ucfirst(app()->environment()) }}</span>
                    </span>
                    <x-monitor::icon :path="Icons::CHEVRON_UP_DOWN" class="h-4 w-4 shrink-0 text-neutral-400"/>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 pb-2">
                @foreach ($groups as $group => $items)
                    @if ($group !== '')
                        <p class="px-2 pb-1 pt-4 text-xs text-neutral-400">{{ $group }}</p>
                    @endif
                    <div class="space-y-px">
                        @foreach ($items as $tabKey => $item)
                            <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                               @class([
                                   'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                                   'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5' => $tab === $tabKey,
                                   'border-transparent text-neutral-500 hover:text-neutral-900' => $tab !== $tabKey,
                               ])>
                                <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600' : 'text-neutral-400 group-hover:text-neutral-600' }}"/>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="p-2">
                <div class="space-y-px pb-2">
                    @foreach ($footerTabs as $tabKey => $item)
                        <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                           @class([
                               'group flex h-9 w-full items-center gap-3 rounded-md border px-2 text-sm',
                               'border-neutral-200 bg-white text-neutral-900 shadow-lg shadow-black/5' => $tab === $tabKey,
                               'border-transparent text-neutral-500 hover:text-neutral-900' => $tab !== $tabKey,
                           ])>
                            <x-monitor::icon :path="$item['icon']" class="h-4 w-4 shrink-0 {{ $tab === $tabKey ? 'text-blue-600' : 'text-neutral-400 group-hover:text-neutral-600' }}"/>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                    <a href="https://github.com/ntm-dev/laravel-monitor" target="_blank" rel="noopener"
                       class="group flex h-9 w-full items-center gap-3 rounded-md border border-transparent px-2 text-sm text-neutral-500 hover:text-neutral-900">
                        <x-monitor::icon :path="Icons::SUPPORT" class="h-4 w-4 shrink-0 text-neutral-400 group-hover:text-neutral-600"/>
                        Support
                    </a>
                </div>
                <div class="flex items-center gap-2.5 border-t border-neutral-100 px-2 pb-1 pt-2.5">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600">{{ $appInitial }}</span>
                    <span class="truncate text-sm text-neutral-700">{{ config('app.name', 'Laravel') }}</span>
                    <span class="ml-auto flex items-center gap-1.5 text-xs text-neutral-400" title="Live · refreshes every {{ $refresh }}s">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                    </span>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-10 bg-neutral-50/80 backdrop-blur">
                <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-5 md:px-8">
                    @if ($isDetail)
                        <div class="min-w-0">
                            <a href="{{ route('monitor.dashboard', ['tab' => $tab] + $range) }}" class="text-xs text-neutral-500 hover:text-neutral-900">{{ $tabs[$tab]['label'] }}</a>
                            <div class="mt-0.5 flex min-w-0 items-center gap-2.5">
                                @if ($tab === 'requests')
                                    <span class="shrink-0 rounded bg-neutral-200/70 px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight text-neutral-600">{{ Str::before($key, ' ') }}</span>
                                    <h1 class="truncate text-2xl font-bold tracking-tight">{{ Str::after($key, ' ') }}</h1>
                                @elseif ($tab === 'exceptions')
                                    <span class="shrink-0 rounded bg-rose-100 px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight text-rose-600">Exception</span>
                                    <h1 class="truncate text-2xl font-bold tracking-tight" title="{{ $detailClass ?? $key }}">{{ $detailClass ? class_basename($detailClass) : 'Exception' }}</h1>
                                @else
                                    <span class="shrink-0 rounded bg-neutral-200/70 px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight text-neutral-600">Job</span>
                                    <h1 class="truncate text-2xl font-bold tracking-tight" title="{{ $key }}">{{ class_basename($key) }}</h1>
                                @endif
                            </div>
                        </div>
                    @else
                        <h1 class="truncate text-2xl font-bold tracking-tight">{{ $title }}</h1>
                    @endif

                    <div class="flex h-8 shrink-0 items-center gap-0.5 rounded-lg border border-neutral-200 bg-white p-0.5 shadow-sm">
                        @foreach (array_keys(Card::periods()) as $value)
                            <a href="{{ route('monitor.dashboard', array_filter(['tab' => $tab, 'period' => $value, 'key' => $key])) }}"
                               @class([
                                   'flex h-full min-w-8 items-center justify-center rounded-md border px-2.5 font-mono text-xs',
                                   'border-blue-500 bg-blue-600 text-white' => ! $hasCustomRange && $period === $value,
                                   'border-transparent text-neutral-500 hover:text-neutral-900' => $hasCustomRange || $period !== $value,
                               ])>{{ strtoupper($value) }}</a>
                        @endforeach
                        <span class="mx-0.5 h-4 w-px bg-neutral-200"></span>
                        <div x-data="{
                                open: false,
                                mode: 'utc',
                                from: '{{ $from }}',
                                to: '{{ $to }}',
                                error: '',
                                apply() {
                                    if (! this.from || ! this.to) { this.error = 'Pick both dates.'; return; }
                                    const now = new Date();
                                    if (new Date(this.to) > now) { this.to = now.toISOString().slice(0, 16); }
                                    if (new Date(this.from) >= new Date(this.to)) { this.error = 'Start must be before end.'; return; }
                                    const params = new URLSearchParams({ tab: '{{ $tab }}', from: this.from, to: this.to });
                                    @if (filled($key)) params.set('key', @js($key)); @endif
                                    window.location = '{{ route('monitor.dashboard') }}?' + params.toString();
                                },
                             }" class="relative h-full">
                            <button type="button" @click="open = ! open"
                                    @class([
                                        'flex h-full items-center gap-1 rounded-md border px-2',
                                        'border-blue-500 bg-blue-600 text-white' => $hasCustomRange,
                                        'border-transparent text-neutral-400 hover:text-neutral-900' => ! $hasCustomRange,
                                    ])>
                                @if ($hasCustomRange)
                                    <span class="font-mono text-xs">{{ $from }} → {{ $to }}</span>
                                @endif
                                <x-monitor::icon :path="Icons::CALENDAR" class="h-4 w-4"/>
                                <x-monitor::icon :path="Icons::CHEVRON_DOWN" :stroke="2" class="h-3 w-3"/>
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                 class="absolute right-0 top-full z-30 mt-2 w-64 rounded-lg bg-neutral-900 p-3 shadow-xl shadow-black/20">
                                <div class="grid grid-cols-2 gap-0.5 rounded-md bg-neutral-800 p-0.5 font-mono text-xs">
                                    <button type="button" @click="mode = 'utc'" class="rounded px-2 py-1.5" :class="mode === 'utc' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">{{ Format::timezone() }}</button>
                                    <button type="button" @click="mode = 'local'" class="rounded px-2 py-1.5" :class="mode === 'local' ? 'bg-neutral-700 text-white' : 'text-neutral-400'">LOCAL</button>
                                </div>
                                <label class="mt-3 block text-xs text-neutral-400">Starting date</label>
                                <input type="datetime-local" x-model="from" max="{{ now()->format(Format::RANGE) }}"
                                       class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
                                <label class="mt-3 block text-xs text-neutral-400">Ending date</label>
                                <input type="datetime-local" x-model="to" max="{{ now()->format(Format::RANGE) }}"
                                       class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-800 px-2 py-1.5 font-mono text-xs text-neutral-200 focus:outline-none">
                                <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-400"></p>
                                <button type="button" @click="apply()"
                                        class="mt-3 w-full rounded-md bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-500">Apply</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Mobile navigation --}}
                <nav class="flex gap-1 overflow-x-auto px-4 pb-2 text-xs md:hidden">
                    @foreach ($groups as $items)
                        @foreach ($items as $tabKey => $item)
                            <a href="{{ route('monitor.dashboard', ['tab' => $tabKey] + $range) }}"
                               @class([
                                   'shrink-0 rounded-md border px-2.5 py-1.5',
                                   'border-neutral-200 bg-white text-neutral-900 shadow-sm' => $tab === $tabKey,
                                   'border-transparent text-neutral-500' => $tab !== $tabKey,
                               ])>{{ $item['label'] }}</a>
                        @endforeach
                    @endforeach
                </nav>
            </header>

            <main class="mx-auto w-full max-w-[1600px] flex-1 px-4 pb-10 md:px-8">
                @php($rangeProps = ['period' => $period, 'from' => $from, 'to' => $to])
                @if ($tab === 'overview')
                    <div class="space-y-4">
                        @livewire('monitor.overview', $rangeProps)
                        @livewire('monitor.application', $rangeProps)
                        @livewire('monitor.users', $rangeProps)
                    </div>
                @elseif ($tab === 'settings')
                    @include('monitor::settings')
                @elseif ($tab === 'requests' && filled($key))
                    @livewire('monitor.request-detail', $rangeProps + ['key' => $key])
                @elseif ($tab === 'jobs' && filled($key))
                    @livewire('monitor.job-detail', $rangeProps + ['key' => $key])
                @elseif ($tab === 'exceptions' && filled($key))
                    @livewire('monitor.exception-detail', $rangeProps + ['key' => $key])
                @else
                    @livewire($tabs[$tab]['component'], $rangeProps + ['limit' => 25])
                @endif
            </main>
        </div>
    </div>

    @livewireScripts

    {{-- Progressive syntax highlighting for stack-trace snippets (highlight.js),
         re-applied after every Livewire poll/morph so it survives DOM patches. --}}
    <script>
        (function () {
            function highlight() {
                if (! window.hljs) return;
                document.querySelectorAll('[data-line-code]').forEach(function (el) {
                    el.innerHTML = window.hljs.highlight(el.textContent, { language: 'php', ignoreIllegals: true }).value;
                });
            }

            function hookLivewire() {
                if (! window.Livewire) return;
                window.Livewire.hook('morphed', highlight);
                window.Livewire.hook('morph', highlight);
            }

            window.addEventListener('load', highlight);
            document.addEventListener('livewire:init', hookLivewire);
            document.addEventListener('livewire:navigated', highlight);
            hookLivewire();
        })();
    </script>
</body>
</html>
