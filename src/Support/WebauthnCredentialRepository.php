<?php

namespace LaravelMonitor\Support;

use LaravelMonitor\Models\MonitorWebauthnCredential;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class WebauthnCredentialRepository
{
    public function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]),
        ))->create();
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        $credential = MonitorWebauthnCredential::query()
            ->where('credential_id', base64_encode($publicKeyCredentialId))
            ->first();

        return $credential === null ? null : $this->serializer()->deserialize($credential->public_key, CredentialRecord::class, 'json');
    }

    public function saveNewCredentialRecord(CredentialRecord $credentialRecord, string $label): void
    {
        // `created_at` is not in MonitorWebauthnCredential::$fillable (the model has
        // $timestamps = false and no DB default for the column), so it's set via direct
        // property assignment below rather than through the mass-assigned array.
        $credential = new MonitorWebauthnCredential([
            'user_id' => $credentialRecord->userHandle,
            'credential_id' => base64_encode($credentialRecord->publicKeyCredentialId),
            'public_key' => $this->serializer()->serialize($credentialRecord, 'json'),
            'sign_count' => $credentialRecord->counter,
            'label' => $label,
        ]);
        $credential->created_at = now();
        $credential->save();
    }

    public function updateCredentialRecord(CredentialRecord $credentialRecord): void
    {
        MonitorWebauthnCredential::query()
            ->where('credential_id', base64_encode($credentialRecord->publicKeyCredentialId))
            ->update([
                'public_key' => $this->serializer()->serialize($credentialRecord, 'json'),
                'sign_count' => $credentialRecord->counter,
            ]);
    }
}
