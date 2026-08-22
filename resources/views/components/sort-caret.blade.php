{{-- Stacked up/down caret pair for a sortable column header — same markup
     Requests uses (see resources/views/livewire/requests.blade.php), pulled
     out here since Issues needs it on six columns across two tables. --}}
@props(['field', 'sortBy', 'sortDirection'])
<div class="flex flex-col gap-[2px]">
    <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection === 'asc' ? 'border-b-blue-500' : '' }}"></div>
    <div class="inline-block size-1.75 h-0 w-0 border-t-0 border-r-[3.5px] border-b-[4px] border-l-[3.5px] border-solid border-t-transparent border-r-transparent border-l-transparent max-md:hidden {{ $sortBy === $field && $sortDirection !== 'asc' ? 'border-b-blue-500' : '' }} rotate-180"></div>
</div>
