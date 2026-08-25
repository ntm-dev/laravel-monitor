<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Concerns\ResolvesUserNames;
use LaravelMonitor\Models\MonitorUser;

class ResolvesUserNamesTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): object
    {
        return new class
        {
            use ResolvesUserNames;

            public function names(array $ids): array
            {
                return $this->resolveNames($ids);
            }
        };
    }

    public function test_it_resolves_a_display_name_for_a_guard_qualified_id(): void
    {
        config(['auth.guards.web' => ['driver' => 'session', 'provider' => 'monitor_users']]);

        $user = MonitorUser::create([
            'name' => 'Web User',
            'email' => 'web-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);

        $names = $this->resolver()->names(["web:{$user->id}"]);

        $this->assertSame('Web User', $names["web:{$user->id}"]);
    }

    public function test_two_guards_with_the_same_raw_id_resolve_to_distinct_names(): void
    {
        // Two guards can independently hand out the same id to two entirely
        // different users — resolveNames() must resolve each against its
        // own guard's provider, never conflate them into one entry.
        config(['auth.guards.web' => ['driver' => 'session', 'provider' => 'monitor_users']]);
        config(['auth.guards.operator' => ['driver' => 'session', 'provider' => 'nonexistent_provider']]);

        $user = MonitorUser::create([
            'name' => 'Real User',
            'email' => 'real-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);

        $names = $this->resolver()->names(["web:{$user->id}", "operator:{$user->id}"]);

        $this->assertSame('Real User', $names["web:{$user->id}"]);
        $this->assertSame("User #{$user->id} (operator)", $names["operator:{$user->id}"]);
    }

    public function test_an_id_with_no_resolvable_provider_falls_back_to_a_generic_label(): void
    {
        $names = $this->resolver()->names(['ghost-guard:999']);

        $this->assertSame('User #999 (ghost-guard)', $names['ghost-guard:999']);
    }

    public function test_empty_ids_return_an_empty_map(): void
    {
        $this->assertSame([], $this->resolver()->names([]));
    }

    public function test_a_legacy_unprefixed_id_is_keyed_by_its_original_raw_value(): void
    {
        // Recorded before guard-qualification existed — resolveNames() must
        // key its result by the exact id it was given, or a caller doing
        // $names[$row->user_id] can never find it again.
        $names = $this->resolver()->names([1]);

        $this->assertArrayHasKey(1, $names);
    }
}
