<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;
use RuntimeException;

/**
 * Mints the KVX1 token that goes in an admission's QR code (ADR-0033).
 *
 * Signs with the newest key that may still sign — retired keys keep verifying
 * but never sign again, which is what makes rotation safe for tokens already
 * in circulation.
 *
 * Each issue mints a fresh `nonce`, so reissuing a token strands the previous
 * QR without touching the admission's state. That is the invalidation path
 * the Admission backlog asks for ("issuance, rotation, and invalidation"):
 * revoking the *admission* is a different, heavier act that also feeds the
 * delta.
 */
final class IssueAdmissionToken
{
    /**
     * How long a minted token stays valid. Long enough that an attendee can
     * be sent their QR weeks ahead, short enough that a leaked one does not
     * outlive the event by much.
     */
    public const int LIFETIME_DAYS = 60;

    public function __construct(
        private readonly AdmissionTokenCodec $codec,
    ) {}

    /**
     * @param  list<string>  $dayIds  event-day ULIDs; empty means every day
     */
    public function handle(Admission $admission, array $dayIds = []): string
    {
        $key = $this->signingKeyFor($admission->brand_id);

        $payload = new AdmissionTokenPayload(
            admissionId: $admission->id,
            eventId: $admission->event_id,
            nonce: (string) Str::ulid(),
            issuedAt: now()->getTimestamp(),
            expiresAt: now()->addDays(self::LIFETIME_DAYS)->getTimestamp(),
            dayIds: $dayIds,
        );

        return $this->codec->encode($payload, $key->kid, $key->private_key);
    }

    private function signingKeyFor(string $brandId): EntranceSigningKey
    {
        // withoutBrandScope + explicit brand (ADR-0010): tokens are minted
        // from queue workers and console commands with no ambient brand.
        $key = EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('retired_at')
            ->whereNotNull('activated_at')
            ->where('activated_at', '<=', now())
            ->where('expires_at', '>', now())
            ->orderByDesc('activated_at')
            ->first();

        if ($key === null) {
            throw new RuntimeException(sprintf(
                'Brand [%s] has no active entrance signing key; run `admission:rotate-signing-key` '
                .'before issuing admission tokens (ADR-0033).',
                $brandId,
            ));
        }

        return $key;
    }
}
