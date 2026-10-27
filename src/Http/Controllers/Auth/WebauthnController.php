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

        // Challenge/ids are raw binary: use the library serializer (base64url), not json_encode().
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

        // Session expired or never populated; deserialize(null) would throw a TypeError.
        abort_if($storedOptions === null, 422);

        $options = $serializer->deserialize(
            $storedOptions,
            PublicKeyCredentialCreationOptions::class,
            'json',
        );

        try {
            $credential = $serializer->deserialize(json_encode($validated['response']), PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            // Client-controlled payload: any deserialization failure is a 422.
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

        // Raw binary challenge: see registerOptions().
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

        // Session expired or never populated; see register().
        abort_if($storedOptions === null, 422);

        $options = $serializer->deserialize(
            $storedOptions,
            PublicKeyCredentialRequestOptions::class,
            'json',
        );

        try {
            $credential = $serializer->deserialize(json_encode($validated['response']), PublicKeyCredential::class, 'json');
        } catch (\Throwable $e) {
            // Client-controlled payload: any deserialization failure is a 422.
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

        // Passkey login skips the TOTP challenge; the passkey is the second factor.
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
