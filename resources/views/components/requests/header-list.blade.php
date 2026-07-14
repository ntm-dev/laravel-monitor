{{-- HeaderList: name/value rows styled like the General/User summary cards
     (label ... dotted line ... value) instead of a plain two-column list. --}}
@props(['headers' => []])
@if (empty($headers))
    <p class="text-xs text-neutral-400 dark:text-neutral-500">No headers recorded.</p>
@else
    <dl class="space-y-2 text-sm">
        @foreach ($headers as $name => $value)
            <div class="flex items-baseline gap-3">
                <dt class="shrink-0 text-neutral-500 dark:text-neutral-400" title="{{ $name }}">{{ $name }}</dt>
                <div class="h-0 flex-1 border-b-2 border-dotted border-neutral-200 dark:border-white/10"></div>
                <dd class="max-w-[60%] break-words text-right font-mono text-xs text-neutral-800 dark:text-neutral-200">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
