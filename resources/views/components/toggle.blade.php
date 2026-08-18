{{-- Checkbox styled as an on/off switch. Submits value "1" when checked. --}}
@props(['name', 'checked' => false])
<label class="relative inline-flex cursor-pointer items-center">
    <input type="checkbox" name="{{ $name }}" value="1" class="peer sr-only" @checked($checked) {{ $attributes }}>
    <div class="h-5 w-9 rounded-full bg-neutral-200 shadow-neu-inset transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-neutral-200 after:shadow-neu-sm after:transition-all peer-checked:after:translate-x-4 peer-checked:after:bg-blue-600 dark:bg-neutral-800 dark:shadow-neu-dark-inset dark:after:bg-neutral-900 dark:after:shadow-neu-dark-sm dark:peer-checked:after:bg-purple-500"></div>
</label>
