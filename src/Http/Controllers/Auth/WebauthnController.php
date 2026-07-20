<?php

namespace LaravelMonitor\Http\Controllers\Auth;

use Cose\Algorithm\Signature\ECDSA\ES256;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaravelMonitor\Models\MonitorUser;
use LaravelMonitor\Support\OptionalAuthMethod;
use LaravelMonitor\Support\WebauthnCredentialRepository;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
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

        $options = $serializer->deserialize(
            $request->session()->get('monitor_webauthn_creation_options'),
            PublicKeyCredentialCreationOptions::class,
            'json',
        );
        $credential = $serializer->deserialize(json_encode($validated['response']), PublicKeyCredential::class, 'json');

        abort_unless($credential->response instanceof AuthenticatorAttestationResponse, 422);

        $credentialRecord = AuthenticatorAttestationResponseValidator::create($this->ceremonyStepManagerFactory($request)->creationCeremony())
            ->check($credential->response, $options, $request->getHost());

        $repository->saveNewCredentialRecord($credentialRecord, $validated['label']);
        $request->session()->forget('monitor_webauthn_creation_options');

        return response()->json(['status' => 'ok']);
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
