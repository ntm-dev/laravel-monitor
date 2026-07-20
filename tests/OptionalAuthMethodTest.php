<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelMonitor\Support\OptionalAuthMethod;

class OptionalAuthMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_is_available_when_google2fa_and_bacon_qr_code_are_installed(): void
    {
        $this->assertTrue(OptionalAuthMethod::totpAvailable());
    }

    public function test_passkeys_are_available_when_webauthn_lib_is_installed(): void
    {
        $this->assertTrue(OptionalAuthMethod::passkeysAvailable());
    }

    public function test_oauth_is_unavailable_for_a_provider_with_no_configured_client_id(): void
    {
        config(['monitor.auth.oauth.google.client_id' => null]);

        $this->assertFalse(OptionalAuthMethod::oauthAvailable('google'));
    }

    public function test_oauth_is_available_for_google_once_a_client_id_is_configured(): void
    {
        config(['monitor.auth.oauth.google.client_id' => 'test-client-id']);

        $this->assertTrue(OptionalAuthMethod::oauthAvailable('google'));
    }

    public function test_oauth_for_apple_also_requires_the_socialiteproviders_apple_package(): void
    {
        config(['monitor.auth.oauth.apple.client_id' => 'test-client-id']);

        $this->assertTrue(OptionalAuthMethod::oauthAvailable('apple'));
    }
}
