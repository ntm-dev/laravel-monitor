<?php

namespace LaravelMonitor\Support;

class OptionalAuthMethod
{
    public static function totpAvailable(): bool
    {
        return class_exists(\PragmaRX\Google2FA\Google2FA::class)
            && class_exists(\BaconQrCode\Writer::class);
    }

    public static function passkeysAvailable(): bool
    {
        return class_exists(\Webauthn\CeremonyStep\CeremonyStepManagerFactory::class);
    }

    public static function oauthAvailable(string $provider): bool
    {
        if (! class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            return false;
        }

        if ($provider === 'apple' && ! class_exists(\SocialiteProviders\Apple\Provider::class)) {
            return false;
        }

        $config = config("monitor.auth.oauth.{$provider}", []);

        return filled($config['client_id'] ?? null);
    }
}
