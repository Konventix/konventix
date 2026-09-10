<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Registration\Actions\Concerns\ConsumesQuotas;
use Konventix\Registration\Actions\Concerns\GuardsSeatingGrants;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;

/**
 * Adds grants to an already-confirmed registration as a *delta* (ADR-0036).
 *
 * The original order and its invoice are never touched — an issued invoice is
 * immutable (ADR-0031), and cancel-and-recreate would void a paid document to
 * accommodate what is usually a free change. Instead the added grants become
 * their own `RegistrationAmendment`, whose ULID is the idempotency key both
 * downstream modules key on.
 *
 * Money is conditional, and that is the point. Adding crew badges costs an
 * exhibitor nothing — they come with the package — so a free amendment emits
 * `RegistrationAmended` alone and Commerce never hears about it. Only an
 * amendment carrying explicit `$lines` requests settlement, and then with its
 * own request key so Commerce writes a second order rather than treating it
 * as a replay of the first.
 *
 * `$lines` is passed in rather than derived: `GrantData` carries no price and
 * a type's `price_declaration` prices the *type*, not an individual added
 * grant, so pricing two extra crew badges off the booth fee would be wrong.
 * Registration declares, Commerce calculates (ADR-0017).
 *
 * Amendments consume quota like any other materialization, all-or-nothing —
 * an amendment that would push a sold-out quota past capacity is rejected, so
 * venue capacity stays truthful.
 *
 * Type changes are deliberately *not* here: changing a registration's type
 * re-opens settlement, approval and gate, so ADR-0036 keeps that as
 * cancel-and-recreate.
 */
final class AmendRegistration
{
    use ConsumesQuotas;
    use GuardsSeatingGrants;

    public function __construct(
        private readonly RequestSettlement $requestSettlement,
    ) {}

    /**
     * @param  list<GrantData>  $grants  the grants being ADDED, not the new total
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>|null  $lines
     */
    public function handle(
        Registration $registration,
        array $grants,
        ?array $lines = null,
        ?string $note = null,
    ): RegistrationAmendment {
        return DB::transaction(function () use ($registration, $grants, $lines, $note): RegistrationAmendment {
            $this->guardConfirmed($registration);
            $this->guardGrants($grants);

            // Explicit loadMissing(): Model::shouldBeStrict() (non-production)
            // forbids lazy-loading an unloaded relation through the magic
            // property, and a caller may only have had the registration's ID.
            $registrationType = $registration->loadMissing('registrationType')->registrationType;

            $addedAdmissions = $this->admissionUnits($grants);

            if ($addedAdmissions > 0) {
                $this->consumeQuotas($registrationType, $addedAdmissions);
            }

            $amendment = RegistrationAmendment::create([
                'registration_id' => $registration->id,
                'grants' => $grants,
                'lines' => $lines,
                'note' => $note,
                'amended_at' => now(),
            ]);

            event(new RegistrationAmended(
                registrationId: $registration->id,
                amendmentId: $amendment->id,
                registrationTypeId: $registrationType->id,
                eventId: $registration->event_id,
                brandId: $registration->brand_id,
                registrantUserId: $registration->registrant_user_id,
                grants: array_map(fn (GrantData $grant): array => $grant->toEventPayload(), $grants),
                amendedAt: $amendment->amended_at->toIso8601String(),
            ));

            // A free amendment stops here: no lines means no money, so no
            // order and no second invoice (ADR-0036).
            if ($lines !== null && $lines !== [] && $registrationType->settlement->requestsSettlement()) {
                $this->requestSettlement->handle(
                    registration: $registration,
                    registrationType: $registrationType,
                    requestKey: $amendment->id,
                    lines: $lines,
                );
            }

            return $amendment;
        });
    }

    private function guardConfirmed(Registration $registration): void
    {
        if ($registration->status !== RegistrationStatus::Confirmed) {
            throw IllegalRegistrationStateTransitionException::forTransition(
                $registration,
                RegistrationStatus::Confirmed,
            );
        }
    }

    /**
     * @param  list<GrantData>  $grants
     */
    private function guardGrants(array $grants): void
    {
        if ($grants === []) {
            throw new InvalidArgumentException(
                'An amendment must add at least one grant (ADR-0036) — an empty delta is not an amendment.',
            );
        }

        // The same seating rules as on the type (seating design §7.1): an
        // amendment that adds a seat is a second claim keyed on itself, and
        // it needs categories and a mode like the original.
        $this->guardSeatingGrants($grants);
    }

    /**
     * @param  list<GrantData>  $grants
     */
    private function admissionUnits(array $grants): int
    {
        $units = 0;

        foreach ($grants as $grant) {
            if ($grant->kind === GrantKind::Admission) {
                $units += $grant->quantity;
            }
        }

        return $units;
    }
}
