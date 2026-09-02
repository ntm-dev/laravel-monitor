<?php

namespace LaravelMonitor\Http\Headings;

use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;

/**
 * Heading for a user detail page: the resolved display name as title, the
 * raw guard-qualified user_id (see ResolvesUserNames) as tooltip.
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
