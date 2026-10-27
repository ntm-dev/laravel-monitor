{{-- Placeholder rows shown during a user-initiated round trip (the call site excludes $refresh).
     Put it in its own tbody beside the real one; match `columns` and `rows` to the real table. --}}
@props(['columns', 'rows' => 6])
@for ($i = 0; $i < $rows; $i++)
    <tr>
        @for ($j = 0; $j < $columns; $j++)
            <td class="py-2 pr-2">
                <div class="monitor-skeleton h-3 rounded bg-neutral-100 dark:bg-neutral-800"></div>
            </td>
        @endfor
    </tr>
@endfor
