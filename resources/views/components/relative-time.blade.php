{{-- Ticking "X ago": server-rendered via Carbon, then recomputed on each monitorClock tick with
     Intl.RelativeTimeFormat. 'narrow' + numeric 'always' and truncated units match Carbon's short form. --}}
@props(['at', 'locale' => null])
{{-- The timestamp lives in data-at, which morph updates on reused rows; tick() re-reads it
     and watch() re-runs tick() when it changes. --}}
<span data-at="{{ $at->getTimestamp() * 1000 }}"
      x-data="{
        locale: @js($locale ?? app()->getLocale()),
        text: @js($at->diffForHumans(short: true)),
        frame: null,
        {{-- Deferred to the next frame so tick() runs after the whole morph settles. --}}
        watch() {
            new MutationObserver(() => {
                cancelAnimationFrame(this.frame);
                this.frame = requestAnimationFrame(() => this.tick());
            }).observe(this.$el, { attributes: true, attributeFilter: ['data-at'] });
        },
        {{-- nowSeconds: the monitorClock tick; falls back to the live clock when absent. --}}
        tick(nowSeconds) {
            const nowMs = nowSeconds === undefined ? Date.now() : nowSeconds * 1000;
            const diffSec = Math.trunc((nowMs - Number(this.$el.dataset.at)) / 1000);
            const rtf = new Intl.RelativeTimeFormat(this.locale, { numeric: 'always', style: 'narrow' });
            const abs = Math.abs(diffSec);
            this.text = abs < 60 ? rtf.format(-diffSec, 'second')
                : abs < 3600 ? rtf.format(-Math.trunc(diffSec / 60), 'minute')
                : abs < 86400 ? rtf.format(-Math.trunc(diffSec / 3600), 'hour')
                : rtf.format(-Math.trunc(diffSec / 86400), 'day');
        },
     }"
     x-init="watch()"
     x-effect="tick($store.monitorClock?.now)"
     x-text="text">{{ $at->diffForHumans(short: true) }}</span>
