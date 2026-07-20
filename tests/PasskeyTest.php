<?php

namespace LaravelMonitor\Tests;

use CBOR\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Models\MonitorWebauthnCredential;
use Livewire\Livewire;
use ParagonIE\ConstantTime\Base64UrlSafe;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_options_are_returned_for_an_authenticated_user(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->postJson('/monitor/webauthn/register/options')
            ->assertOk()
            ->assertJsonStructure(['challenge', 'rp', 'user']);
    }

    public function test_a_valid_registration_response_persists_a_credential_for_the_current_user(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $optionsResponse = $this->postJson('/monitor/webauthn/register/options')->json();

        [$attestationResponse, $credentialId] = $this->fakeAttestationResponseFor($optionsResponse);

        $this->postJson('/monitor/webauthn/register', [
            'label' => 'Test device',
            'response' => $attestationResponse,
        ])->assertOk();

        $this->assertDatabaseHas((new MonitorWebauthnCredential())->getTable(), [
            'user_id' => $owner->id,
            'label' => 'Test device',
        ]);
    }

    public function test_removing_a_passkey_only_removes_the_owning_users_row(): void
    {
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();
        $credential = $this->createPasskeyFor($owner, 'cred-1', 'To remove');

        Livewire::test(Team::class)->call('removePasskey', $credential->id);

        $this->assertDatabaseMissing((new MonitorWebauthnCredential())->getTable(), ['id' => $credential->id]);
    }

    public function test_removing_someone_elses_passkey_does_nothing(): void
    {
        $other = MonitorUser::create([
            'name' => 'Other', 'email' => 'other-passkey-owner@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $credential = $this->createPasskeyFor($other, 'cred-2', 'Not mine');

        Livewire::test(Team::class)->call('removePasskey', $credential->id);

        $this->assertDatabaseHas((new MonitorWebauthnCredential())->getTable(), ['id' => $credential->id]);
    }

    /**
     * MonitorWebauthnCredential has $timestamps = false and its `created_at`
     * column has no DB default, and `created_at` is deliberately not in
     * $fillable (production code sets it via direct property assignment —
     * see WebauthnCredentialRepository::saveNewCredentialRecord()). Mirror
     * that here rather than mass-assigning it through create().
     */
    protected function createPasskeyFor(MonitorUser $user, string $credentialId, string $label): MonitorWebauthnCredential
    {
        $credential = new MonitorWebauthnCredential([
            'user_id' => $user->id, 'credential_id' => $credentialId,
            'public_key' => 'key', 'label' => $label,
        ]);
        $credential->created_at = now();
        $credential->save();

        return $credential;
    }

    /**
     * Build a minimal "software authenticator" registration (attestation) response by hand.
     *
     * There is no bundled fixture generator for a full registration ceremony in
     * web-auth/webauthn-lib. This constructs the wire-shape JSON a real browser's
     * navigator.credentials.create() would produce, using a "none" attestation
     * format (no signature over the attestation itself — the ceremony's
     * creationCeremony() step list has no CheckSignature step at all; that only
     * applies to the *assertion* (login) ceremony). We still generate a real ES256
     * keypair so Task 6's fakeAssertionResponseFor() can sign with it.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function fakeAttestationResponseFor(array $optionsResponse): array
    {
        $host = 'localhost';
        $origin = 'http://localhost';

        $challenge = Base64UrlSafe::decodeNoPadding($optionsResponse['challenge']);

        $clientDataJSON = json_encode([
            'type' => 'webauthn.create',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
        ]);

        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($keyPair);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $credentialId = random_bytes(16);

        $encoder = new Encoder();
        $coseKey = $encoder->encode([
            1 => 2,   // kty: EC2
            3 => -7,  // alg: ES256
            -1 => 1,  // crv: P-256
            -2 => $x,
            -3 => $y,
        ]);

        $rpIdHash = hash('sha256', $host, true);
        $flags = chr(0x41); // bit 0 (UP) + bit 6 (AT)
        $signCount = pack('N', 1);
        $aaguid = str_repeat("\0", 16);
        $credentialIdLength = pack('n', strlen($credentialId));

        $authenticatorData = $rpIdHash.$flags.$signCount.$aaguid.$credentialIdLength.$credentialId.$coseKey;

        $attestationObject = $encoder->encode([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => $authenticatorData,
        ]);

        $credentialIdBase64Url = Base64UrlSafe::encodeUnpadded($credentialId);

        $response = [
            'id' => $credentialIdBase64Url,
            'rawId' => $credentialIdBase64Url,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => base64_encode($attestationObject),
            ],
        ];

        return [$response, $credentialIdBase64Url];
    }
}
