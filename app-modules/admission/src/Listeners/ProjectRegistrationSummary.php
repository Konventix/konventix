<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\RegistrationSummary;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationStandingAnnounced;

/**
 * Keeps Admission's copy of a registration's type and status (attendee-list
 * design §3, §4.4, #329; ADR-0004): the attendee list labels each ticket with
 * the type from here, and the ticket detail shows the registration as
 * Admission knows it, never from Registration's tables.
 *
 * - `RegistrationConfirmed` creates or updates the row as `confirmed`.
 * - `RegistrationCancelled` carries no brand, so it can only flip an existing
 *   row to `cancelled`; a registration this table never heard of stays absent.
 * - `RegistrationStandingAnnounced` is the backfill (`registration:announce-standing`)
 *   and may create the row. Its `status` wins; an emitter that predates that
 *   field leaves it null, and the row then takes `confirmed` or
 *   `unconfirmed` from `$confirmed`.
 *
 * A null type id or name in a payload means "not carried", never "erase":
 * an older emitter must not wipe what a newer one stored.
 *
 * Only a projection may listen to `RegistrationStandingAnnounced`, which is
 * why this class's name starts with `Project`. The imports are
 * `Registration\Events\` classes, the sanctioned cross-module dependency
 * (ADR-0004, `RegistrationCommerceImportBoundaryTest`). Admission is Core, so
 * there is no Pennant short-circuit.
 */
final class ProjectRegistrationSummary
{
    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_UNCONFIRMED = 'unconfirmed';

    public function handleConfirmed(RegistrationConfirmed $event): void
    {
        $this->project(
            registrationId: $event->registrationId,
            brandId: $event->brandId,
            registrationTypeId: $event->registrationTypeId,
            typeName: $event->registrationTypeName,
            status: self::STATUS_CONFIRMED,
        );
    }

    public function handleCancelled(RegistrationCancelled $event): void
    {
        RegistrationSummary::withoutBrandScope()
            ->where('registration_id', $event->registrationId)
            ->update(['status' => self::STATUS_CANCELLED]);
    }

    public function handleStandingAnnounced(RegistrationStandingAnnounced $event): void
    {
        $this->project(
            registrationId: $event->registrationId,
            brandId: $event->brandId,
            registrationTypeId: $event->registrationTypeId,
            typeName: $event->registrationTypeName,
            status: $event->status ?? ($event->confirmed ? self::STATUS_CONFIRMED : self::STATUS_UNCONFIRMED),
        );
    }

    private function project(
        string $registrationId,
        string $brandId,
        ?string $registrationTypeId,
        ?string $typeName,
        string $status,
    ): void {
        $summary = RegistrationSummary::withoutBrandScope()
            ->where('registration_id', $registrationId)
            ->first() ?? new RegistrationSummary(['registration_id' => $registrationId]);

        // brand_id from the payload, never the ambient context (ADR-0010):
        // the backfill runs with no brand at all.
        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'registration_type_id' => $registrationTypeId,
            'type_name' => $typeName,
            'status' => $status,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}
