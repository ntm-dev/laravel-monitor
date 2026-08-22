{{-- Client-side ticking "X ago" — the server-rendered text (from Carbon's
     own diffForHumans(), so it matches everywhere else in the dashboard on
     first paint) only updates when the page's own wire:poll fires. Once
     Alpine hydrates, this instead recomputes every second from the raw
     timestamp using the browser's native Intl.RelativeTimeFormat, so a
     count in seconds keeps advancing every second, one in minutes every
     minute, and so on — without reimplementing Carbon's per-locale
     pluralization rules in JS. --}}
@props(['at', 'locale' => null])
<span x-data="{
        atMs: {{ $at->getTimestamp() * 1000 }},
        locale: @js($locale ?? app()->getLocale()),
        text: @js($at->diffForHumans(short: true)),
        tick() {
            const diffSec = Math.round((Date.now() - this.atMs) / 1000);
            const rtf = new Intl.RelativeTimeFormat(this.locale, { numeric: 'auto' });
            const abs = Math.abs(diffSec);
            const diffMin = Math.round(diffSec / 60);
            const diffHour = Math.round(diffMin / 60);
            const diffDay = Math.round(diffHour / 24);
            this.text = abs < 60 ? rtf.format(-diffSec, 'second')
                : Math.abs(diffMin) < 60 ? rtf.format(-diffMin, 'minute')
                : Math.abs(diffHour) < 24 ? rtf.format(-diffHour, 'hour')
                : rtf.format(-diffDay, 'day');
        },
     }"
     x-init="setInterval(() => tick(), 1000)"
     x-text="text">{{ $at->diffForHumans(short: true) }}</span>
