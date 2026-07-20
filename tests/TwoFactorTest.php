<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    public function test_logging_in_a_totp_enabled_user_redirects_to_the_challenge_instead_of_the_dashboard(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/monitor/two-factor-challenge');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    public function test_a_correct_totp_code_on_the_challenge_completes_login(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $code = (new Google2FA())->getCurrentOtp(decrypt($user->getRawOriginal('totp_secret'), false));

        $this->post('/monitor/two-factor-challenge', ['code' => $code])
            ->assertRedirect('/monitor');

        $this->assertTrue(Auth::guard('monitor')->check());
        $this->assertSame($user->id, Auth::guard('monitor')->id());
    }

    public function test_a_wrong_totp_code_on_the_challenge_does_not_log_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/monitor/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    public function test_a_correct_recovery_code_logs_in_and_is_removed_from_the_stored_list(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();
        $plainRecoveryCode = 'RECOVERY01';
        $user->update(['totp_recovery_codes' => [Hash::make($plainRecoveryCode), Hash::make('OTHERCODE')]]);

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/monitor/two-factor-challenge', ['code' => $plainRecoveryCode])
            ->assertRedirect('/monitor');

        $this->assertTrue(Auth::guard('monitor')->check());
        $this->assertCount(1, $user->refresh()->totp_recovery_codes);
    }

    public function test_reusing_a_spent_recovery_code_fails(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $user = $this->createTotpEnabledUser();
        $user->update(['totp_recovery_codes' => [Hash::make('ONETIME01')]]);

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/monitor/two-factor-challenge', ['code' => 'ONETIME01']);
        $this->withoutMonitorAuth();

        $this->post('/monitor/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/monitor/two-factor-challenge', ['code' => 'ONETIME01'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(Auth::guard('monitor')->check());
    }

    protected function createTotpEnabledUser(): MonitorUser
    {
        return MonitorUser::create([
            'name' => 'Totp User',
            'email' => 'totp-user@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => [],
        ]);
    }

    public function test_disabling_totp_requires_the_correct_current_password(): void
    {
        $owner = $this->actingAsOwner();
        $owner->update([
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableTotp', 'wrong-password');

        $this->assertTrue($owner->refresh()->hasTotpEnabled());
    }

    public function test_disabling_totp_with_the_correct_password_clears_it(): void
    {
        $owner = $this->actingAsOwner();
        $owner->update([
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => ['a-hash'],
        ]);

        Livewire::test(Team::class)->call('disableTotp', 'password');

        $owner->refresh();
        $this->assertFalse($owner->hasTotpEnabled());
        $this->assertNull($owner->totp_secret);
        $this->assertNull($owner->totp_recovery_codes);
    }

    public function test_owner_can_disable_another_members_totp(): void
    {
        $this->actingAsOwner();
        $member = MonitorUser::create([
            'name' => 'Locked Out', 'email' => 'locked-out@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(), 'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableMemberTotp', $member->id);

        $this->assertFalse($member->refresh()->hasTotpEnabled());
    }

    public function test_admin_cannot_disable_another_members_totp(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'admin-2fa-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $this->actingAs($admin, MonitorUser::guardName());

        $member = MonitorUser::create([
            'name' => 'Locked Out 2', 'email' => 'locked-out-2@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
            'totp_secret' => (new Google2FA())->generateSecretKey(),
            'totp_enabled_at' => now(), 'totp_recovery_codes' => [],
        ]);

        Livewire::test(Team::class)->call('disableMemberTotp', $member->id)
            ->assertForbidden();

        $this->assertTrue($member->refresh()->hasTotpEnabled());
    }
}
