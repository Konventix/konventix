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
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationAmended
{
    /**
     * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
     */
    public function __construct(
        public string $registrationId,
        public string $amendmentId,
        public string $registrationTypeId,
        public string $eventId,
        public string $brandId,
        public array $grants,
        public string $amendedAt,
    ) {}
}
