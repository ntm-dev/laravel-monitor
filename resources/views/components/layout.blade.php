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
    {{-- Pretty-prints SQL (one clause per line) before it's syntax-highlighted —
         exposes window.sqlFormatter.format(). See Requests\Timeline's
         sqlHighlighted(). --}}
    <script src="https://cdn.jsdelivr.net/npm/sql-formatter@4.0.2/dist/sql-formatter.min.js"></script>
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
                    // Neumorphism (soft UI) shadow tokens. Surfaces share the
                    // page's own background colour — depth comes only from
                    // this dual light/dark shadow pair, never a border.
                    // "-inset" variants read as pressed/selected (nav's
                    // active item, a toggled switch, an input's data well);
                    // the plain variants read as raised (cards, buttons,
                    // idle nav items).
                    boxShadow: {
                        'neu': '-6px -6px 12px rgba(255,255,255,0.9), 6px 6px 12px rgba(0,0,0,0.15)',
                        'neu-sm': '-3px -3px 6px rgba(255,255,255,0.9), 3px 3px 6px rgba(0,0,0,0.12)',
                        'neu-lg': '-10px -10px 20px rgba(255,255,255,0.9), 10px 10px 24px rgba(0,0,0,0.18)',
                        'neu-inset': 'inset -3px -3px 6px rgba(255,255,255,0.8), inset 3px 3px 6px rgba(0,0,0,0.15)',
                        'neu-dark': '-6px -6px 12px rgba(255,255,255,0.06), 6px 6px 12px rgba(0,0,0,0.7)',
                        'neu-dark-sm': '-3px -3px 6px rgba(255,255,255,0.05), 3px 3px 6px rgba(0,0,0,0.65)',
                        'neu-dark-lg': '-10px -10px 20px rgba(255,255,255,0.07), 10px 10px 24px rgba(0,0,0,0.75)',
                        'neu-dark-inset': 'inset -3px -3px 6px rgba(255,255,255,0.04), inset 3px 3px 6px rgba(0,0,0,0.65)',
                    },
                },
            },
        };
    </script>
    <style>[x-cloak] { display: none !important; }</style>
    {{-- highlight.js only ships the light "github" theme (loaded above) — no
         dark variant exists to swap in, so override just the token colours
         that read poorly against a dark background instead of loading a
         second stylesheet. --}}
    <style>
        .dark .hljs-string { color: #60748c; }

        /* Firefox: thumb colour matched to neutral-300/neutral-700 (light/dark),
           transparent track so it doesn't add its own bar under the chart.
           Set on html (not body) since that's where .dark is toggled — a rule
           scoped to body would always win over an inherited value from html,
           leaving body's own scrollbar stuck light regardless of theme. */
        html {
            scrollbar-color: rgb(212 212 212) transparent;
        }
        html.dark {
            scrollbar-color: rgb(64 64 64) transparent;
        }
        /* Chromium/Safari: the default scrollbar otherwise stays light-themed
           (native OS chrome) even inside a dark-mode page. */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background-color: rgb(212 212 212);
            border-radius: 9999px;
        }
        /* ".dark ::-webkit-scrollbar-thumb" alone only matches descendants of
           .dark — it can't reach html's own scrollbar (the outer page/viewport
           bar), since html isn't a descendant of itself. Add the self-selector
           too so that one darkens as well. */
        .dark ::-webkit-scrollbar-thumb,
        html.dark::-webkit-scrollbar-thumb {
            background-color: rgb(64 64 64);
        }
    </style>
    <style>
        /* Neumorphic surfaces drop the border-based focus ring, so every
           interactive element gets this real ring back via :focus-visible —
           without it, keyboard-only navigation would have no visible focus
           indicator at all (see components/layout.blade.php boxShadow tokens
           above for why borders are gone). */
        a, button, input, select, textarea, [tabindex] {
            outline: none;
        }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, [tabindex]:focus-visible {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 2px;
        }
        /* Dark mode's primary accent is purple (see boxShadow tokens above
           for the rest of the neumorphic system) — the focus ring follows
           it so it doesn't read as a leftover blue. */
        .dark a:focus-visible, .dark button:focus-visible, .dark input:focus-visible, .dark select:focus-visible, .dark textarea:focus-visible, .dark [tabindex]:focus-visible {
            outline-color: rgb(168 85 247);
        }
        /* List rows sit inside a `divide-y` container (Tailwind renders that
           as border-top on every row but the first), so a hovered row's own
           box-shadow gets visually cut by that line — both the one on its
           own top edge and the one belonging to the row right after it.
           `divide-y`'s selector (.divide-y > :not([hidden]) ~ :not([hidden]))
           out-specifies a plain hover utility class, hence !important here
           rather than a Tailwind class. */
        tr:hover, tr:hover + tr {
            border-top-color: transparent !important;
        }
    </style>
    @livewireStyles
</head>
<body class="min-h-screen bg-neutral-200 font-sans text-neutral-800 antialiased dark:bg-neutral-800 dark:text-neutral-100">
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
