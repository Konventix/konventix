<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * Grants were added to an already-confirmed registration (ADR-0036) — the
 * delta counterpart to `RegistrationConfirmed`. Emitted by
 * `Konventix\Registration\Actions\AmendRegistration`.
 *
 * `grants` carries **only the added** grants, never the registration's full
 * grant set, so `Konventix\Admission` issues exactly the delta rather than
 * re-deriving what it already issued at confirmation. `amendmentId` is the
 * idempotency key that makes that safe on replay: Admission asks "have I
 * issued for *this amendment*?", not "does this registration have any
 * admissions?".
 *
 * Money is deliberately absent. An amendment that costs something emits
 * `RegistrationSettlementRequested` separately, keyed on the same
 * `amendmentId`; a free one — crew badges included in an exhibitor's
 * package — emits this event alone and never reaches Commerce.
 *
 * `registrantUserId` travels for the same reason as on `RegistrationConfirmed`:
 * a seating grant added by amendment becomes a second seat claim, and
 * Resources authorises its picker from the payload.
 *
 * Scalars only (ADR-0004).
 *
 * @phpstan-type GrantPayload array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 */
final readonly class RegistrationAmended
{
    /**
     * @param  list<GrantPayload>  $grants
     */
    public function __construct(
        public string $registrationId,
        public string $amendmentId,
        public string $registrationTypeId,
        public string $eventId,
        public string $brandId,
        public string $registrantUserId,
        public array $grants,
        public string $amendedAt,
    ) {}
}
