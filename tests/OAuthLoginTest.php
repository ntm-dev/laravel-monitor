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

    public function test_a_callback_when_socialite_throws_shows_error_and_creates_nothing(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        // Mock the Socialite factory to return a driver that throws InvalidStateException
        // (e.g., when state is tampered, missing, or user denies consent). This simulates
        // a client-controlled OAuth callback failure that should not surface as a 500 error.
        $mockDriver = \Mockery::mock();
        $mockDriver->shouldReceive('user')->andThrow(new \Laravel\Socialite\Two\InvalidStateException());

        $this->app->bind(\Laravel\Socialite\Contracts\Factory::class, function () use ($mockDriver) {
            $mock = \Mockery::mock(\Laravel\Socialite\Contracts\Factory::class);
            $mock->shouldReceive('driver')->with('google')->andReturn($mockDriver);
            return $mock;
        });

        $this->get('/monitor/oauth/google/callback')
            ->assertSessionHasErrors('email');

        $this->assertFalse(Auth::guard(MonitorUser::guardName())->check());
        $this->assertDatabaseMissing((new MonitorOauthAccount())->getTable(), ['provider' => 'google']);
    }

    public function test_apple_callback_with_a_matching_email_logs_the_user_in(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        config(['monitor.auth.oauth.apple.client_id' => 'test-apple-client-id']);
        $this->withoutMonitorAuth();

        Socialite::fake('apple', (new SocialiteUser())->map([
            'id' => 'apple-123', 'name' => 'Owner', 'email' => 'owner@example.com',
        ]));

        $this->get('/monitor/oauth/apple/callback')->assertRedirect('/monitor');

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
    }

    public function test_apple_login_button_is_disabled_without_a_configured_client_id(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        config(['monitor.auth.oauth.apple.client_id' => null]);
        $this->withoutMonitorAuth();

        $this->get('/monitor/login')->assertSeeText('Install laravel/socialite');
    }

    public function test_a_redirect_for_an_unrouted_provider_name_is_a_404(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->get('/monitor/oauth/somefakeprovider/redirect')->assertNotFound();
    }

    public function test_a_redirect_for_google_without_a_configured_client_id_is_a_404(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        config(['monitor.auth.oauth.google.client_id' => null]);
        $this->withoutMonitorAuth();

        $this->get('/monitor/oauth/google/redirect')->assertNotFound();
    }
}
