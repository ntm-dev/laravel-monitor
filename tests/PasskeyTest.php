<?php

namespace LaravelMonitor\Tests;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Models\MonitorWebauthnCredential;
use LaravelMonitor\Support\WebauthnCredentialRepository;
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

    public function test_registration_with_no_pending_session_options_returns_a_4xx_not_a_500(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        // No prior call to /monitor/webauthn/register/options — the session never had
        // (or had and lost, e.g. via expiry) monitor_webauthn_creation_options.
        $this->postJson('/monitor/webauthn/register', [
            'label' => 'Test device',
            'response' => ['id' => 'anything'],
        ])->assertStatus(422);
    }

    public function test_registration_with_a_malformed_response_payload_returns_422(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $this->postJson('/monitor/webauthn/register/options')->assertOk();

        $this->postJson('/monitor/webauthn/register', [
            'label' => 'Test device',
            'response' => ['not' => 'a valid webauthn response', 'at' => ['all']],
        ])->assertStatus(422);
    }

    /**
     * A genuine double-click/network-retry replay resubmits the exact same attestation
     * for a session whose stored creation options are single-use — the controller
     * forgets them from the session as soon as the first submission succeeds, so a
     * second identical HTTP call would instead hit the session-expiry guard (case 1),
     * never reaching the repository. This exercises the repository fix (case 3)
     * directly: saving the same credential record twice must update the existing row
     * rather than throwing a unique-constraint QueryException.
     */
    public function test_registering_the_same_credential_twice_does_not_throw(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);

        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $optionsResponse = $this->postJson('/monitor/webauthn/register/options')->json();
        [$attestationResponse, $credentialId] = $this->fakeAttestationResponseFor($optionsResponse);

        $this->postJson('/monitor/webauthn/register', [
            'label' => 'First registration',
            'response' => $attestationResponse,
        ])->assertOk();

        $repository = new WebauthnCredentialRepository();
        $credentialRecord = $repository->findOneByCredentialId(Base64UrlSafe::decodeNoPadding($credentialId));

        $repository->saveNewCredentialRecord($credentialRecord, 'Second registration');

        $this->assertDatabaseCount((new MonitorWebauthnCredential())->getTable(), 1);
        $this->assertDatabaseHas((new MonitorWebauthnCredential())->getTable(), [
            'user_id' => $owner->id,
            'label' => 'Second registration',
        ]);
    }

    public function test_authentication_options_do_not_require_a_prior_login(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();

        $this->postJson('/monitor/webauthn/authenticate/options')
            ->assertOk()
            ->assertJsonStructure(['challenge']);
    }

    public function test_a_valid_authentication_response_logs_in_the_owning_user(): void
    {
        Gate::define('viewMonitor', fn ($user = null) => true);
        $this->withoutMonitorAuth();
        $owner = MonitorUser::query()->where('email', 'owner@example.com')->firstOrFail();

        $optionsResponse = $this->postJson('/monitor/webauthn/authenticate/options')->json();
        [$assertionResponse] = $this->fakeAssertionResponseFor($optionsResponse, $owner);

        $this->postJson('/monitor/webauthn/authenticate', ['response' => $assertionResponse])
            ->assertRedirect('/monitor');

        $this->assertSame($owner->id, Auth::guard(MonitorUser::guardName())->id());
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
     * @return array{0: array<string, mixed>, 1: string, 2: \OpenSSLAsymmetricKey}
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

        // web-auth/webauthn-lib ^5.3 requires spomky-labs/cbor-php ^3.0, whose
        // Encoder::encode(array) convenience (for an arbitrary PHP array) was
        // removed in favour of building a typed CBORObject tree explicitly.
        $coseKey = (string) MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),   // kty: EC2
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),  // alg: ES256
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),  // crv: P-256
            MapItem::create(NegativeIntegerObject::create(-2), ByteStringObject::create($x)),
            MapItem::create(NegativeIntegerObject::create(-3), ByteStringObject::create($y)),
        ]);

        $rpIdHash = hash('sha256', $host, true);
        $flags = chr(0x41); // bit 0 (UP) + bit 6 (AT)
        $signCount = pack('N', 1);
        $aaguid = str_repeat("\0", 16);
        $credentialIdLength = pack('n', strlen($credentialId));

        $authenticatorData = $rpIdHash.$flags.$signCount.$aaguid.$credentialIdLength.$credentialId.$coseKey;

        $attestationObject = (string) MapObject::create([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), MapObject::create([])),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData)),
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

        return [$response, $credentialIdBase64Url, $keyPair];
    }

    /**
     * Build a minimal "software authenticator" authentication (assertion) response by hand,
     * for the credential this method registers itself.
     *
     * There's no existing passkey to authenticate against yet at the point this runs — the
     * calling test only knows *who* should end up logged in ($user), not any credential ID —
     * so this registers a real credential for $user first (temporarily impersonating them
     * via actingAs(), the same "software authenticator" as fakeAttestationResponseFor(), whose
     * ES256 keypair it keeps), then signs a fresh assertion with that same keypair. It restores
     * the unauthenticated state (withoutMonitorAuth()) before returning, so the calling test's
     * own POST to /monitor/webauthn/authenticate is what actually establishes the session.
     *
     * @return array{0: array<string, mixed>}
     */
    protected function fakeAssertionResponseFor(array $optionsResponse, MonitorUser $user): array
    {
        Gate::define('viewMonitor', fn ($u = null) => true);
        $this->actingAs($user, MonitorUser::guardName());

        $registrationOptions = $this->postJson('/monitor/webauthn/register/options')->json();
        [$attestationResponse, $credentialId, $privateKey] = $this->fakeAttestationResponseFor($registrationOptions);

        $this->postJson('/monitor/webauthn/register', [
            'label' => 'Passkey login fixture',
            'response' => $attestationResponse,
        ])->assertOk();

        $this->withoutMonitorAuth();

        $host = 'localhost';
        $origin = 'http://localhost';

        $challenge = Base64UrlSafe::decodeNoPadding($optionsResponse['challenge']);

        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
        ]);

        // Same rpIdHash + flags + counter shape as registration's authenticatorData, minus
        // the attested-credential-data block (AT flag unset — see AuthenticatorDataLoader::
        // load(), which only parses that block when the flag is present) since this is an
        // authentication, not a registration, ceremony. Counter must exceed the value stored
        // during registration (1) for CheckCounter to accept it.
        $rpIdHash = hash('sha256', $host, true);
        $flags = chr(0x01); // bit 0 (UP) only
        $signCount = pack('N', 2);
        $authenticatorData = $rpIdHash.$flags.$signCount;

        $dataToSign = $authenticatorData.hash('sha256', $clientDataJSON, true);
        openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        // Usernameless/discoverable-credential flow: authenticate() passes null as the
        // "already known" userHandle to AuthenticatorAssertionResponseValidator::check(), so
        // CheckUserHandle instead requires the *response's* userHandle to identify the user —
        // it must match the CredentialRecord's userHandle, which registration stored as the
        // raw (string) user ID (see AuthenticatorAttestationResponseValidator::check()).
        $response = [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'authenticatorData' => base64_encode($authenticatorData),
                'signature' => base64_encode($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded((string) $user->id),
            ],
        ];

        return [$response];
    }
}
