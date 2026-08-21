{{-- Mono uppercase label chip, e.g. EXCEPTIONS / ROUTES. --}}
<span {{ $attributes->merge(['class' => 'self-start rounded-md bg-neutral-200 dark:bg-neutral-800 px-1.5 py-0.5 font-mono text-xs uppercase tracking-tight text-neutral-500 shadow-neu-inset dark:text-neutral-400 dark:shadow-neu-dark-inset']) }}>{{ $slot }}</span>
