<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use LaravelMonitor\Models\MonitorOauthAccount;
use LaravelMonitor\Models\MonitorUser;

class OAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.auth.oauth.google.client_id' => 'test-client-id']);
    }

    public function test_a_callback_with_an_email_matching_an_existing_user_logs_them_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-123',
            'name' => 'Owner',
            'email' => 'owner@example.com',
        ]));

        $this->get('/monitor/oauth/google/callback')->assertRedirect('/monitor');

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
        $this->assertDatabaseHas((new MonitorOauthAccount())->getTable(), [
            'user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'google-123',
        ]);
    }

    public function test_a_callback_with_an_unmatched_email_shows_an_error_and_creates_nothing(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-456',
            'name' => 'Nobody',
            'email' => 'not-a-member@example.com',
        ]));

        $this->get('/monitor/oauth/google/callback')->assertSessionHasErrors('email');

        $this->assertFalse(Auth::guard(MonitorUser::guardName())->check());
        $this->assertDatabaseMissing((new MonitorUser())->getTable(), ['email' => 'not-a-member@example.com']);
    }

    public function test_a_second_login_through_the_same_provider_reuses_the_linked_account(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        Socialite::fake('google', (new SocialiteUser())->map([
            'id' => 'google-789', 'name' => 'Owner', 'email' => 'owner@example.com',
        ]));
        $this->get('/monitor/oauth/google/callback');
        $this->withoutMonitorAuth();
        $this->get('/monitor/oauth/google/callback');

        $this->assertSame(1, MonitorOauthAccount::query()->where('provider', 'google')->where('provider_user_id', 'google-789')->count());
    }

    public function test_google_login_button_is_disabled_without_a_configured_client_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        config(['monitor.auth.oauth.google.client_id' => null]);
        $this->withoutMonitorAuth();

        $this->get('/monitor/login')->assertSeeText('Install laravel/socialite');
    }
}
