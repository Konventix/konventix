<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;

/**
 * Server-side half of KVX1 verification: resolves the token's `kid` to a
 * published key, then hands the actual cryptography to
 * `AdmissionTokenCodec`, which stays database-free so the Slice 6 offline
 * client can reuse it verbatim.
 *
 * Accepts signatures from **retired-but-unexpired** keys, not just the
 * current one: rotation must not invalidate tokens already in attendees'
 * wallets. Expiry of the token itself is reported separately from a bad
 * signature — "not ours" and "ours but stale" need different words at a door.
 */
final class VerifyAdmissionToken
{
    public function __construct(
        private readonly AdmissionTokenCodec $codec,
    ) {}

    /**
     * @return array{payload: AdmissionTokenPayload|null, expired: bool}
     */
    public function handle(string $token, string $brandId): array
    {
        $kid = $this->codec->kidOf($token);

        if ($kid === null) {
            return ['payload' => null, 'expired' => false];
        }

        $key = EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('kid', $kid)
            ->publishable()
            ->first();

        if ($key === null) {
            return ['payload' => null, 'expired' => false];
        }

        $payload = $this->codec->verify($token, $key->public_key);

        if ($payload === null) {
            return ['payload' => null, 'expired' => false];
        }

        return [
            'payload' => $payload,
            'expired' => $payload->expiresAt <= now()->getTimestamp(),
        ];
    }
}
