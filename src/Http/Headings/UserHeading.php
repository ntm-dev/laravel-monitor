<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

/**
 * Heading for a user detail page: display name as title, raw user_id as tooltip.
 */
class UserHeading
{
    use ResolvesUserNames;

    public function __invoke(string $key): Heading
    {
        $name = $this->resolveNames([$key])[$key];

        return new Heading(
            badge: 'User',
            badgeClass: 'bg-neutral-200/70 text-neutral-600',
            heading: $name,
            titleAttr: $key,
            pageTitle: $name,
        );
    }
}
