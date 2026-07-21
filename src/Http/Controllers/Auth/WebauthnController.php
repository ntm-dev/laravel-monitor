<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Cose\Algorithm\Signature\ECDSA\ES256;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\OptionalAuthMethod;
use LaravelMonitor\Support\WebauthnCredentialRepository;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class WebauthnController
{
    public function registerOptions(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $actor = $request->user(MonitorUser::guardName());
        $repository = $this->repository();

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(config('app.name', 'Laravel Monitor'), $request->getHost()),
            PublicKeyCredentialUserEntity::create($actor->email, (string) $actor->id, $actor->name),
            random_bytes(32),
            pubKeyCredParams: [PublicKeyCredentialParameters::createPk(ES256::ID)],
        );

        // The library's normalizers base64url-encode the challenge/user id/etc. into the
        // wire shape a browser's navigator.credentials.create() expects — json_encode()ing
        // the options object directly would fail (its challenge/id properties are raw
        // binary, not valid UTF-8), so we serialize via the library's own serializer and
        // hand PHP's json_encode entirely to it (JsonResponse::fromJsonString avoids
        // re-encoding an already-JSON string).
        $json = $repository->serializer()->serialize($options, 'json');

        $request->session()->put('monitor_webauthn_creation_options', $json);

        return JsonResponse::fromJsonString($json);
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $validated = $request->validate(['label' => ['required', 'string', 'max:255'], 'response' => ['required']]);
        $repository = $this->repository();
        $serializer = $repository->serializer();

        $storedOptions = $request->session()->get('monitor_webauthn_creation_options');

        // Session expired (or was never populated) between fetching registration
        // options and submitting the response — deserialize() would otherwise be
        // handed null and throw a TypeError before any validation runs.
        abort_if($storedOptions === null, 422);

        $options = $serializer->deserialize(
            $storedOptions,
            PublicKeyCredentialCreationOptions::class,
            'json',
        );

        try {
            $credential = $serializer->deserialize(json_encode($validated['response']), PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            // The "response" payload is attacker/client-controlled and can be malformed
            // in ways that throw before we ever reach a validation guard — e.g.
            // Symfony\Component\Serializer\Exception\* for the wrong shape, or
            // Webauthn\Exception\InvalidDataException for a mismatched id/rawId. Treat
            // any deserialization failure as a plain 422, same as the guard below.
            abort(422);
        }

        abort_unless($credential instanceof PublicKeyCredential && $credential->response instanceof AuthenticatorAttestationResponse, 422);

        $credentialRecord = AuthenticatorAttestationResponseValidator::create($this->ceremonyStepManagerFactory($request)->creationCeremony())
            ->check($credential->response, $options, $request->getHost());

        $repository->saveNewCredentialRecord($credentialRecord, $validated['label']);
        $request->session()->forget('monitor_webauthn_creation_options');

        return response()->json(['status' => 'ok']);
    }

    public function authenticateOptions(Request $request): JsonResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );

        // Same reasoning as registerOptions(): $options->challenge is raw binary, so
        // json_encode()ing the object directly (response()->json($options)) would choke on
        // invalid UTF-8 — serialize via the library's own serializer instead and hand PHP's
        // json_encode entirely to it.
        $json = $this->repository()->serializer()->serialize($options, 'json');

        $request->session()->put('monitor_webauthn_request_options', $json);

        return JsonResponse::fromJsonString($json);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        abort_unless(OptionalAuthMethod::passkeysAvailable(), 404);

        $validated = $request->validate(['response' => ['required']]);
        $repository = $this->repository();
        $serializer = $repository->serializer();

        $storedOptions = $request->session()->get('monitor_webauthn_request_options');

        // Same "session expired/never populated" guard as register() — deserialize()
        // would otherwise be handed null and throw a TypeError before any validation runs.
        abort_if($storedOptions === null, 422);

        $options = $serializer->deserialize(
            $storedOptions,
            PublicKeyCredentialRequestOptions::class,
            'json',
        );

        try {
            $credential = $serializer->deserialize(json_encode($validated['response']), PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            // Same rationale as register()'s catch: the "response" payload is
            // attacker/client-controlled and can be malformed in ways that throw
            // before we ever reach a validation guard.
            abort(422);
        }

        abort_unless($credential instanceof PublicKeyCredential && $credential->response instanceof AuthenticatorAssertionResponse, 422);

        $existingRecord = $repository->findOneByCredentialId($credential->rawId);
        abort_if($existingRecord === null, 422);

        $credentialRecord = AuthenticatorAssertionResponseValidator::create($this->ceremonyStepManagerFactory($request)->requestCeremony())
            ->check($existingRecord, $credential->response, $options, $request->getHost(), null);

        $repository->updateCredentialRecord($credentialRecord);
        $request->session()->forget('monitor_webauthn_request_options');

        $user = MonitorUser::query()->findOrFail($credentialRecord->userHandle);

        // Standalone/passwordless login — deliberately does not route through the
        // TOTP challenge (see TwoFactorChallengeController): possessing a
        // previously-registered passkey is itself the second factor.
        Auth::guard(MonitorUser::guardName())->login($user);
        $request->session()->regenerate();

        return redirect()->route('monitor.dashboard');
    }

    protected function repository(): WebauthnCredentialRepository
    {
        return new WebauthnCredentialRepository();
    }

    protected function ceremonyStepManagerFactory(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$request->getSchemeAndHttpHost()]);

        return $factory;
    }
}
