{{-- HTML shell for every dashboard page: head assets, Tailwind config and the
     scripts that survive Livewire morphs. Mirrors Laravel's exception renderer
     layout component. --}}
@props(['title'])
@php($monitorTheme = \LaravelMonitor\Support\Preferences::theme())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $monitorTheme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Monitor</title>
    {{-- Resolve the theme before first paint so there is no light/dark flash. --}}
    <script>
        (function () {
            var root = document.documentElement;
            var pref = root.getAttribute('data-theme') || 'system';
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            function apply() {
                var dark = pref === 'dark' || (pref === 'system' && media.matches);
                root.classList.toggle('dark', dark);
            }
            apply();
            media.addEventListener('change', function () { if (pref === 'system') apply(); });
            // Called by the settings page for instant preview before the form is saved.
            window.monitorApplyTheme = function (next) { pref = next; root.setAttribute('data-theme', next); apply(); };
        })();
    </script>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
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
<body class="min-h-screen bg-neutral-50 font-sans text-neutral-900 antialiased dark:bg-neutral-950 dark:text-neutral-100">
    {{ $slot }}

    @livewireScripts

    {{-- Progressive syntax highlighting for stack-trace snippets (highlight.js),
         re-applied after every Livewire poll/morph so it survives DOM patches. --}}
    <script>
        (function () {
            function highlight() {
                if (! window.hljs) return;
                document.querySelectorAll('[data-line-code]').forEach(function (el) {
                    var language = el.dataset.lang || 'php';
                    el.innerHTML = window.hljs.highlight(el.textContent, { language: language, ignoreIllegals: true }).value;
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
