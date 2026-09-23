<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * A provider identity that is linked to an account right now, restated for
 * backfill. `identity:announce-social-identities` emits it once for every
 * `social_identities` row, so that a read model built after the link was made
 * can learn about the link. The first consumer is Competition's
 * `competition_linked_identities`, used by the Steam signup rule (competition
 * design §5.6).
 *
 * This is its own event rather than a replay of `SocialIdentityLinked`,
 * because that event is an `AuditableEvent`. Re-emitting it would write one
 * "a Steam account was linked" entry per identity into Compliance's audit
 * trail, dated to the replay. That trail is exactly where an account holder
 * looks for a key they did not add. The same reasoning produced
 * `RegistrationOwnershipAnnounced` and `AdmissionOwnershipAnnounced`.
 * **Only a projection may ever listen to it.**
 *
 * It carries no `provider_user_id`. A backfilled consumer needs to know that
 * the link exists and when it was made, not the provider's identifier for
 * the person. A consumer that genuinely needs the id (GameOps'
 * `PlayerGameAccount`) should widen this event on purpose rather than
 * receive the id by default.
 *
 * Scalars only (ADR-0004).
 */
final readonly class SocialIdentityAnnounced
{
    public function __construct(
        public string $userId,
        public string $provider,
        public string $linkedAt,
    ) {}
}
