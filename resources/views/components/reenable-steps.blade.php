{{-- How to turn the master switch back on once the dashboard is unreachable. Shared by Settings' disable dialog and the disabled page. --}}
@props(['file'])
@php
    $t = fn (string $key) => __("monitor::messages.settings.{$key}");
    $codeClass = 'rounded bg-neutral-100 px-1 py-0.5 font-mono text-xs text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200';
@endphp
<div {{ $attributes->merge(['class' => 'text-xs text-neutral-600 dark:text-neutral-300']) }}>
    <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $t('reenable_title') }}</p>
    <ol class="mt-1.5 list-inside list-decimal space-y-1.5">
        <li>
            {{ $t('reenable_edit') }}
            <code class="{{ $codeClass }} mt-1 block w-fit max-w-full break-all">{{ $file }}</code>
            <pre class="mt-1 overflow-x-auto rounded-md bg-neutral-100 px-2 py-1.5 font-mono text-xs text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200"><code>'enabled' => true,</code></pre>
        </li>
        <li>{{ $t('reenable_delete') }}</li>
    </ol>
    <p class="mt-2 text-neutral-500 dark:text-neutral-400">{{ $t('reenable_env_note') }}</p>
    <p class="mt-1 text-neutral-500 dark:text-neutral-400">{{ $t('reenable_restart') }}</p>
</div>
