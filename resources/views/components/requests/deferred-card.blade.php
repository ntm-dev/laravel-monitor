{{-- DeferredCard: fetches an HTML fragment once the page has finished loading
     and shows it in place. An empty response (204) leaves the card hidden. --}}
@props(['url'])
{{-- start deferred card --}}
<div x-data="{ html: '' }"
     x-init="const go = () => fetch(@js($url), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((r) => r.ok ? r.text() : '').then((t) => html = t).catch(() => {}); document.readyState === 'complete' ? go() : window.addEventListener('load', go, { once: true })"
     x-show="html !== ''" x-cloak>
    <div x-html="html"></div>
</div>
{{-- end deferred card --}}
