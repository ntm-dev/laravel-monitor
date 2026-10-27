<?php

namespace LaravelMonitor\Support;

/**
 * The dashboard's user scope: '' (unfiltered), AUTHENTICATED, GUEST, or one
 * user id — a plain string so a card can bind it to a `<select>`.
 */
final class UserFilter
{
    /** Any signed-in user: matches "user_id is not null". */
    public const AUTHENTICATED = '*';

    /** Nobody signed in — the null `user_id` half of AUTHENTICATED. */
    public const GUEST = '-';
}
