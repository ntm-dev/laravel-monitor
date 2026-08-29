<?php

namespace LaravelMonitor\Support;

use Illuminate\Http\Request;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Decodes the `memo` object out of each Livewire component snapshot carried
 * in a request's `components` payload — the shape both a full-page mount and
 * Livewire's shared `/livewire/update` endpoint send. Shared by
 * Monitor::isSelfRequest() (needs memo.path) and
 * IsolateMonitorCookies::payloadTargetsMonitorComponent() (needs memo.name),
 * which otherwise duplicated this same decode-and-iterate loop.
 */
class LivewireSnapshot
{
    /** @return list<array<string, mixed>> */
    public static function memos(Request $request): array
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return [];
        }

        $memos = [];

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;

            if (! is_string($snapshot)) {
                continue;
            }

            $memo = json_decode($snapshot, true)['memo'] ?? null;

            if (is_array($memo)) {
                $memos[] = $memo;
            }
        }

        return $memos;
    }

    /** Each component's `memo.id` — the snapshot's own unique identifier. @return list<string> */
    public static function ids(Request $request): array
    {
        return self::pluck($request, 'id');
    }

    /** Each component's `memo.name` — the alias it was registered under (e.g. "monitor.requests"). @return list<string> */
    public static function names(Request $request): array
    {
        return self::pluck($request, 'name');
    }

    /** Each component's `memo.path` — the URL the component was originally mounted on. @return list<string> */
    public static function paths(Request $request): array
    {
        return self::pluck($request, 'path');
    }

    /**
     * Every component's value for a given memo key (e.g. "locale", "method"),
     * skipping components where it's missing or not a string.
     *
     * @return list<string>
     */
    public static function pluck(Request $request, string $key): array
    {
        $values = [];

        foreach (self::memos($request) as $memo) {
            $value = $memo[$key] ?? null;

            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
