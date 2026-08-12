<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The type's `confirmation_gate` has been passed — the one event that
 * materializes entitlements (SPEC.md "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\ConfirmRegistration`.
 *
 * `grants` is the scalar projection of `RegistrationType::$grants`
 * (`GrantData` collection): `Konventix\Admission`'s listener issues one
 * `Admission` per unit of `quantity` on every `kind: admission` entry, using
 * only this payload (ADR-0004) — never a `RegistrationType` model.
 *
 * Per ADR-0026, a `settlement: free` registration never causes
 * `RegistrationSettlementRequested` to be emitted alongside this event.
 *
 * @property-read list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}> $grants
 */
final class RegistrationConfirmed
{
    /**
     * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $confirmedAt,
        public readonly array $grants,
    ) {}
}
