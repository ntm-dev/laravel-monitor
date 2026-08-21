{{-- Placeholder <tr> rows shown in place of a paginated table's real rows
     while a previousPage/nextPage round trip is in flight. Meant to sit in
     its own <tbody> alongside the real one (a <table> may have more than
     one) so the placeholder bars share the real header's column widths
     instead of drifting out of alignment. `columns` should match the
     table's actual rendered column count; `rows` should match the real
     tbody's current row count so nothing shifts vertically on swap. --}}
@props(['columns', 'rows' => 6])
@for ($i = 0; $i < $rows; $i++)
    <tr>
        @for ($j = 0; $j < $columns; $j++)
            <td class="py-2 pr-2">
                <div class="h-3 rounded bg-neutral-200 dark:bg-neutral-800 shadow-neu-inset dark:shadow-neu-dark-inset"></div>
            </td>
        @endfor
    </tr>
@endfor
