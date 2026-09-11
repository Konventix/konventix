<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\ResourceClaim;

/**
 * The one authorisation the picker has (design §7.4, §9.1): the claim of
 * this registration whose `registrant_user_id` is the signed-in account.
 * No capability is involved — ownership is not something anybody can be
 * granted — so there is no policy either, the same reasoning as
 * `MyRegistrationsController`.
 *
 * Brand-scoped through `BelongsToBrand`, so a registration made with one
 * brand has no seat page on another's host (ADR-0016 from the outside).
 * A `released` claim is a cancelled or rejected registration and has no
 * page any more; where an amendment added a second claim the newest wins
 * (TODO.md carries the multi-claim picker as a follow-up).
 */
final class FindSeatClaimForRegistrant
{
    public function handle(string $registrationId, string $userId): ?ResourceClaim
    {
        return ResourceClaim::query()
            ->where('registration_id', $registrationId)
            ->where('registrant_user_id', $userId)
            ->where('state', '!=', ClaimState::Released)
            ->orderByDesc('created_at')
            ->first();
    }
}
