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
 * `entryConditionKeys` rides **beside** `grants`, never inside one
 * (ADR-0066 §6.4, D-ADM-06): a condition is a property of the act, not of
 * one entitlement it produced. Admission stamps the list on every admission
 * it issues for this registration and hands the `document_at_door` ones to
 * the door; it treats every field as an opaque scalar and reads no
 * Registration table to do it. Empty for every type that declares none,
 * which is every type that existed before this feature.
 *
 * @phpstan-type GrantPayload array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 * @phpstan-type EntryConditionPayload array{key: string, label: string, verification: string, document_hint: string|null}
 */
final class RegistrationConfirmed
{
    /**
     * @param  list<GrantPayload>  $grants
     * @param  list<EntryConditionPayload>  $entryConditionKeys
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $registrantUserId,
        public readonly string $confirmedAt,
        public readonly array $grants,
        public readonly array $entryConditionKeys = [],
    ) {}
}
