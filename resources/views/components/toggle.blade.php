{{-- Checkbox styled as an on/off switch. Submits value "1" when checked. --}}
@props(['name', 'checked' => false])
<label class="relative inline-flex cursor-pointer items-center">
    <input type="checkbox" name="{{ $name }}" value="1" class="peer sr-only" @checked($checked)>
    <div class="h-5 w-9 rounded-full bg-neutral-200 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow-sm after:transition-all peer-checked:bg-blue-600 peer-checked:after:translate-x-4 dark:bg-neutral-700 dark:peer-checked:bg-blue-500"></div>
</label>
