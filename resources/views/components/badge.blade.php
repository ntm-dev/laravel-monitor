{{-- Mono uppercase label chip, e.g. EXCEPTIONS / ROUTES. --}}
<span {{ $attributes->merge(['class' => 'self-start rounded bg-neutral-100 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight text-neutral-500 dark:text-neutral-400']) }}>{{ $slot }}</span>
