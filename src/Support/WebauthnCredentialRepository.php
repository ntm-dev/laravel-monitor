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
        // Upsert on the unique credential_id: a duplicate registration (double-click,
        // retry) updates the row instead of hitting a unique-constraint error.
        $attributes = ['credential_id' => base64_encode($credentialRecord->publicKeyCredentialId)];

        $values = [
            'user_id' => $credentialRecord->userHandle,
            'public_key' => $this->serializer()->serialize($credentialRecord, 'json'),
            'sign_count' => $credentialRecord->counter,
            'label' => $label,
        ];

        // No DB default and $timestamps = false: set created_at only for a new row,
        // so a duplicate registration keeps the original.
        if (! MonitorWebauthnCredential::query()->where($attributes)->exists()) {
            $values['created_at'] = now();
        }

        MonitorWebauthnCredential::query()->updateOrCreate($attributes, $values);
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
