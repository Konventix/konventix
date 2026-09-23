<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Konventix\Competition\Models\EligibleRegistration;

/**
 * Maintains Competition's view of who may enter (D-CMP-05).
 *
 * Two writers for the live path because neither source event carries the
 * whole fact:
 * `RegistrationSubmitted` knows the registrant but not that they are
 * confirmed, and `RegistrationConfirmed` knows the confirmation but not the
 * registrant. Upserting on `registration_id` lets them arrive in either order
 * — which they will, since a queue makes no promises about ordering.
 */
final class ProjectEligibleRegistration
{
    public function submitted(string $registrationId, string $eventId, string $brandId, ?string $userId): EligibleRegistration
    {
        $row = $this->rowFor($registrationId, $eventId, $brandId);

        $row->user_id = $userId;
        $row->save();

        return $row;
    }

    public function confirmed(string $registrationId, string $eventId, string $brandId): EligibleRegistration
    {
        $row = $this->rowFor($registrationId, $eventId, $brandId);

        $row->is_confirmed = true;
        $row->save();

        return $row;
    }

    /**
     * A cancelled or rejected registration no longer lets anybody in (final
     * review, part 2, I1). Neither `RegistrationCancelled` nor
     * `RegistrationRejected` carries a brand or an event, so the row is found
     * by `registration_id` alone, outside the brand scope — the way
     * Resources releases a cancelled registration's bookings. The row stays,
     * with its registrant, so a later announcement can restate it; only the
     * confirmation goes. A registration nobody projected has no row, and
     * then there is nothing to take back.
     */
    public function withdrawn(string $registrationId): void
    {
        EligibleRegistration::withoutBrandScope()
            ->where('registration_id', $registrationId)
            ->update(['is_confirmed' => false]);
    }

    /**
     * The backfill (`registration:announce-standing`): the registration's
     * registrant and whether it is confirmed *now*, both written, so a replay
     * brings a row to the registration's current state whatever the row said
     * before — or creates it, for a registration that never emitted its
     * events.
     */
    public function announced(string $registrationId, string $eventId, string $brandId, string $userId, bool $confirmed): EligibleRegistration
    {
        $row = $this->rowFor($registrationId, $eventId, $brandId);

        $row->user_id = $userId;
        $row->is_confirmed = $confirmed;
        $row->save();

        return $row;
    }

    private function rowFor(string $registrationId, string $eventId, string $brandId): EligibleRegistration
    {
        $row = EligibleRegistration::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->first();

        if ($row !== null) {
            return $row;
        }

        $row = new EligibleRegistration([
            'registration_id' => $registrationId,
            'event_id' => $eventId,
        ]);
        // brand_id from the payload, not ambient context (ADR-0010).
        $row->forceFill(['brand_id' => $brandId]);

        return $row;
    }
}
