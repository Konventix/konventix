<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Spatie\LaravelData\Data;

/**
 * Typed payload for the `registration.waitlist-offer` template (ADR-0009).
 * The claim URL is a signed route that expires with the offer itself.
 */
final class WaitlistOfferMailData extends Data
{
    public function __construct(
        public readonly string $claimUrl,
        public readonly string $offerExpiresAt,
    ) {}
}
