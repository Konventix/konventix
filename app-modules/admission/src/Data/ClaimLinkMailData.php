<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;

/**
 * Typed payload for the `admission.claim-link` template (#292 §4.6,
 * ADR-0009): who invites, to what, and the signed URL that expires with
 * the link row itself (`IssueAdmissionClaimLink::urlFor()`).
 *
 * The inviter is named by handle, never by display name (ADR-0065,
 * D-IDP-15: handle everywhere, mails included) — this mail goes to a
 * third party who has no business learning the manager's real name.
 * Null when the manager is unknown or their read-model row predates the
 * handle column; the template then says "the organizer".
 */
final class ClaimLinkMailData extends Data
{
    public function __construct(
        public readonly ?string $eventName,
        public readonly ?string $managerHandle,
        public readonly string $role,
        public readonly string $claimUrl,
        public readonly string $expiresAt,
    ) {}
}
