<?php

namespace LaravelMonitor\Support;

/**
 * The dashboard's user scope: '' (unfiltered), AUTHENTICATED, or one user id.
 * A plain string throughout so a card can bind it straight to a `<select>`.
 */
final class UserFilter
{
    /**
     * Any signed-in user. Guests are stored with a null `user_id`, so this
     * reads as "user_id is not null", not as a value to match.
     */
    public const AUTHENTICATED = '*';
}
