<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Konventix\Competition\Models\EligibleRegistration;

/**
 * Maintains Competition's view of who may enter (D-CMP-05).
 *
 * Two writers because neither source event carries the whole fact:
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
