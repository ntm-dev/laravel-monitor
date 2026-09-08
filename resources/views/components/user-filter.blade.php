{{-- The dashboard's user scope select, shared by every card offering one.
     Binds wire:model.live="userId", so the host card needs that public
     property (and an updatedUserId() resetting its own paging). --}}
@props(['users'])
<select wire:model.live="userId"
        class="h-8 rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 text-xs text-neutral-600 dark:text-neutral-300 shadow-sm focus:outline-none">
    <option value="">{{ __('monitor::messages.common.all_users') }}</option>
    <option value="{{ \LaravelMonitor\Support\UserFilter::AUTHENTICATED }}">{{ __('monitor::messages.common.authenticated_users') }}</option>
    <option value="{{ \LaravelMonitor\Support\UserFilter::GUEST }}">{{ __('monitor::messages.common.guest_users') }}</option>
    @foreach ($users as $user)
        <option value="{{ $user->id }}">{{ $user->name }}</option>
    @endforeach
</select>
