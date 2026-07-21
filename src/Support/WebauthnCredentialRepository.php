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
        // updateOrCreate() keyed on the unique credential_id (rather than create()) so a
        // replayed/duplicate registration for the same authenticator (e.g. a double-click
        // or network retry re-submitting the same attestation) updates the existing row
        // instead of throwing an uncaught unique-constraint QueryException.
        $attributes = ['credential_id' => base64_encode($credentialRecord->publicKeyCredentialId)];

        $values = [
            'user_id' => $credentialRecord->userHandle,
            'public_key' => $this->serializer()->serialize($credentialRecord, 'json'),
            'sign_count' => $credentialRecord->counter,
            'label' => $label,
        ];

        // `created_at` has no DB default and the model has $timestamps = false, so
        // updateOrCreate()'s save() won't populate it automatically — set it explicitly
        // in $values, but only for a genuinely new row, so a duplicate registration
        // doesn't reset an existing credential's created_at.
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
