{{-- Checkbox styled as an on/off switch. Submits value "1" when checked.
     peer-disabled: styles (on the sibling div, same as peer-checked: below —
     peer only tracks siblings, not the parent label) are the only visual
     cue this gives when disabled (e.g. inside Settings' read-only
     <fieldset>) — the switch itself has no native browser disabled look. --}}
@props(['name', 'checked' => false])
<label class="relative inline-flex cursor-pointer items-center">
    <input type="checkbox" name="{{ $name }}" value="1" class="peer sr-only" @checked($checked) {{ $attributes }}>
    {{-- Unchecked+disabled needs no override — it's already the same gray
         as unchecked+enabled. Checked+disabled does: peer-checked:peer-
         disabled:!bg-* (stacked variants, ! beats peer-checked:bg-blue-600)
         gives it its own darker gray, distinct from the plain off-state,
         so "was on" stays readable even though it can't be toggled. --}}
    <div class="h-5 w-9 rounded-full bg-neutral-200 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow-sm after:transition-all peer-checked:bg-blue-600 peer-checked:after:translate-x-4 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 peer-checked:peer-disabled:!bg-neutral-400 dark:bg-neutral-700 dark:peer-checked:bg-blue-500 dark:peer-checked:peer-disabled:!bg-neutral-500"></div>
</label>
