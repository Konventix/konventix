<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The type's `confirmation_gate` has been passed — the one event that
 * materializes entitlements (SPEC.md "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\ConfirmRegistration`.
 *
 * `grants` is the scalar projection of `RegistrationType::$grants`
 * (`GrantData::toEventPayload()`): `Konventix\Admission`'s listener issues
 * one `Admission` per unit of `quantity` on every `kind: admission` entry,
 * Resources books `kind: resource_claim` entries first-fit and turns
 * `kind: seating` entries into seat claims — each using only this payload
 * (ADR-0004), never a `RegistrationType` model.
 *
 * `registrantUserId` is here for the seat claim: Resources authorises the
 * picker against it without ever reading a `Registration` row (seating
 * design §7.1).
 *
 * Per ADR-0026, a `settlement: free` registration never causes
 * `RegistrationSettlementRequested` to be emitted alongside this event.
 *
 * @phpstan-type GrantPayload array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 */
final class RegistrationConfirmed
{
    /**
     * @param  list<GrantPayload>  $grants
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $registrantUserId,
        public readonly string $confirmedAt,
        public readonly array $grants,
    ) {}
}
