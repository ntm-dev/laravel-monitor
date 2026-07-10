<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitor — {{ config('app.name', 'Laravel') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen antialiased">
    <header class="border-b border-gray-800 bg-gray-900/60 backdrop-blur sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 font-bold text-sm">M</span>
                <div>
                    <h1 class="font-semibold leading-tight">Monitor</h1>
                    <p class="text-xs text-gray-500 leading-tight">{{ config('app.name', 'Laravel') }} · {{ app()->environment() }}</p>
                </div>
            </div>
            <nav class="flex items-center gap-1 text-sm">
                @foreach (['1h' => '1 hour', '6h' => '6 hours', '24h' => '24 hours', '7d' => '7 days'] as $value => $label)
                    <a href="{{ route('monitor.dashboard', ['period' => $value]) }}"
                       class="px-3 py-1.5 rounded-lg {{ $period === $value ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-4 grid gap-4 grid-cols-1 lg:grid-cols-2">
        <div class="lg:col-span-2">
            @livewire('monitor.overview', ['period' => $period])
        </div>

        @livewire('monitor.requests', ['period' => $period])
        @livewire('monitor.slow-queries', ['period' => $period])
        @livewire('monitor.exceptions', ['period' => $period])
        @livewire('monitor.jobs', ['period' => $period])
        @livewire('monitor.schedule', ['period' => $period])
        @livewire('monitor.cache', ['period' => $period])
        @livewire('monitor.outgoing-requests', ['period' => $period])
        @livewire('monitor.users', ['period' => $period])
        @livewire('monitor.mail', ['period' => $period])

        <div class="lg:col-span-2">
            @livewire('monitor.logs', ['period' => $period])
        </div>
    </main>

    <footer class="max-w-7xl mx-auto px-4 pb-6 text-xs text-gray-600">
        Auto-refreshes every 10 seconds · Prune old entries with <code class="text-gray-500">php artisan monitor:prune</code>
    </footer>

    @livewireScripts
</body>
</html>
