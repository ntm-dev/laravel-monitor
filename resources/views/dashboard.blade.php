@php
    $tabs = [
        'overview' => ['label' => 'Overview', 'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
        'requests' => ['label' => 'Requests', 'icon' => 'M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418'],
        'exceptions' => ['label' => 'Exceptions', 'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z'],
        'queries' => ['label' => 'Slow Queries', 'icon' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125'],
        'jobs' => ['label' => 'Queue Jobs', 'icon' => 'M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122'],
        'schedule' => ['label' => 'Schedule', 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        'cache' => ['label' => 'Cache', 'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z'],
        'outgoing' => ['label' => 'Outgoing HTTP', 'icon' => 'M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25'],
        'mail' => ['label' => 'Mail & Notifications', 'icon' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75'],
        'users' => ['label' => 'Users', 'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        'logs' => ['label' => 'Logs', 'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
    ];

    $components = [
        'requests' => 'monitor.requests',
        'exceptions' => 'monitor.exceptions',
        'queries' => 'monitor.slow-queries',
        'jobs' => 'monitor.jobs',
        'schedule' => 'monitor.schedule',
        'cache' => 'monitor.cache',
        'outgoing' => 'monitor.outgoing-requests',
        'mail' => 'monitor.mail',
        'users' => 'monitor.users',
        'logs' => 'monitor.logs',
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tabs[$tab]['label'] }} — Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        night: {
                            950: '#08080f',
                            900: '#0d0d17',
                            800: '#12121e',
                            700: '#1c1c2b',
                            600: '#2a2a3f',
                        },
                    },
                },
            },
        };
    </script>
    @livewireStyles
</head>
<body class="bg-night-950 text-gray-100 min-h-screen antialiased">
    <div class="flex min-h-screen">

        <aside class="hidden md:flex w-60 shrink-0 flex-col border-r border-night-700/60 bg-night-900/70 sticky top-0 h-screen">
            <div class="flex items-center gap-2.5 px-4 py-4 border-b border-night-700/60">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-violet-600 shadow-lg shadow-violet-600/30">
                    <svg class="h-4.5 w-4.5 text-white" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-sm leading-tight">Monitor</p>
                    <p class="text-[11px] text-gray-500 leading-tight truncate">{{ config('app.name', 'Laravel') }}</p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto p-3 space-y-0.5">
                @foreach ($tabs as $key => $item)
                    <a href="{{ route('monitor.dashboard', ['tab' => $key, 'period' => $period]) }}"
                       @class([
                           'flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] transition-colors',
                           'bg-violet-500/15 text-violet-200 font-medium' => $tab === $key,
                           'text-gray-400 hover:text-gray-100 hover:bg-night-800' => $tab !== $key,
                       ])>
                        <svg class="h-4 w-4 shrink-0 {{ $tab === $key ? 'text-violet-400' : 'text-gray-500' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                        </svg>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="p-4 border-t border-night-700/60 space-y-1">
                <p class="text-[11px] text-gray-500 flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Live · refreshes every 10s
                </p>
                <p class="text-[11px] text-gray-600">{{ app()->environment() }} environment</p>
            </div>
        </aside>

        <div class="flex-1 min-w-0 flex flex-col">
            <header class="sticky top-0 z-10 border-b border-night-700/60 bg-night-950/80 backdrop-blur">
                <div class="flex items-center justify-between gap-4 px-4 md:px-6 py-3">
                    <h1 class="font-semibold truncate">{{ $tabs[$tab]['label'] }}</h1>
                    <div class="inline-flex items-center rounded-lg border border-night-700 bg-night-900 p-0.5 text-xs">
                        @foreach (['1h' => '1h', '6h' => '6h', '24h' => '24h', '7d' => '7d'] as $value => $label)
                            <a href="{{ route('monitor.dashboard', ['tab' => $tab, 'period' => $value]) }}"
                               @class([
                                   'px-3 py-1.5 rounded-md transition-colors',
                                   'bg-violet-600 text-white font-medium' => $period === $value,
                                   'text-gray-400 hover:text-gray-100' => $period !== $value,
                               ])>{{ $label }}</a>
                        @endforeach
                    </div>
                </div>

                {{-- Mobile navigation --}}
                <nav class="md:hidden flex gap-1 overflow-x-auto px-4 pb-2 text-xs">
                    @foreach ($tabs as $key => $item)
                        <a href="{{ route('monitor.dashboard', ['tab' => $key, 'period' => $period]) }}"
                           @class([
                               'shrink-0 rounded-lg px-2.5 py-1.5',
                               'bg-violet-500/15 text-violet-200' => $tab === $key,
                               'text-gray-400' => $tab !== $key,
                           ])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </header>

            <main class="flex-1 p-4 md:p-6">
                @if ($tab === 'overview')
                    <div class="space-y-4">
                        @livewire('monitor.overview', ['period' => $period])

                        <div class="grid gap-4 grid-cols-1 xl:grid-cols-2">
                            @livewire('monitor.requests', ['period' => $period])
                            @livewire('monitor.exceptions', ['period' => $period])
                            @livewire('monitor.jobs', ['period' => $period])
                            @livewire('monitor.slow-queries', ['period' => $period])
                        </div>
                    </div>
                @else
                    @livewire($components[$tab], ['period' => $period, 'limit' => 25])
                @endif
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
