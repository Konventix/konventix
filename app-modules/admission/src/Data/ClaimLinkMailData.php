<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;

/**
 * Typed payload for the `admission.claim-link` template (#292 §4.6,
 * ADR-0009): who invites, to what, and the signed URL that expires with
 * the link row itself (`IssueAdmissionClaimLink::urlFor()`).
 */
final class ClaimLinkMailData extends Data
{
    public function __construct(
        public readonly ?string $eventName,
        public readonly ?string $managerName,
        public readonly string $role,
        public readonly string $claimUrl,
        public readonly string $expiresAt,
    ) {}
}
