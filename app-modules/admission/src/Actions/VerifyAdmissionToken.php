<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Konventix\Admission\Models\Admission;
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

    /**
     * Whether $payload's nonce is no longer the one $admission currently
     * carries (design doc §5, `CheckInRejectionReason::NonceSuperseded`).
     *
     * An admission with no stored hash — nothing has rotated since before
     * this enforcement shipped — accepts anything: that is what keeps
     * every token already in circulation working across the deploy,
     * exactly the gap design doc §2.1 found (nothing ever compared a
     * stored nonce). `IssueAdmissionToken`'s own docblock records why that
     * is not a temporary state a backfill will later close.
     *
     * Timing-safe comparison: this runs against whatever a scanner
     * presents, the same reasoning `AdmissionTokenCodec::verify()` gives
     * for treating an unverifiable token as a rejection rather than an
     * error, never a fatal one.
     */
    public function isSuperseded(AdmissionTokenPayload $payload, Admission $admission): bool
    {
        if ($admission->token_nonce_hash === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload->nonce, (string) config('admission.token_pepper'));

        return ! hash_equals($admission->token_nonce_hash, $expected);
    }
}
