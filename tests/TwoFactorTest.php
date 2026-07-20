<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Models\MonitorUser;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;
    public function test_starting_enrollment_generates_a_secret_but_does_not_persist_it_yet(): void
    {
        $owner = $this->actingAsOwner();

        Livewire::test(Team::class)->call('startEnrollingTotp');

        $this->assertNull($owner->refresh()->totp_secret);
    }

    public function test_confirming_with_the_correct_code_enables_totp_and_generates_recovery_codes(): void
    {
        $owner = $this->actingAsOwner();
        $google2fa = new Google2FA();

        $component = Livewire::test(Team::class)->call('startEnrollingTotp');
        $secret = $component->get('totpSecret');

        $component->call('confirmTotp', $google2fa->getCurrentOtp($secret));

        $owner->refresh();
        $this->assertNotNull($owner->totp_secret);
        $this->assertNotNull($owner->totp_enabled_at);
        $this->assertCount(8, $owner->totp_recovery_codes);
    }

    public function test_confirming_with_a_wrong_code_enables_nothing(): void
    {
        $owner = $this->actingAsOwner();

        $component = Livewire::test(Team::class)->call('startEnrollingTotp');
        $component->call('confirmTotp', '000000');

        $owner->refresh();
        $this->assertNull($owner->totp_secret);
        $this->assertNull($owner->totp_enabled_at);
    }

    protected function actingAsOwner(): MonitorUser
    {
        // TestCase::setUp() already created and logged in "Test Owner" — reuse it directly.
        return MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
    }
}
