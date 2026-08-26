<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registration on a settlement that moves money through the platform
 * (`instant` or `invoice` — see `RegistrationSettlement::requestsSettlement()`)
 * needs an order (SPEC.md "Public contract"). Commerce consumes this and
 * creates the order (and, for `invoice`, the invoice) from the payload alone
 * — never from a Registration model (ADR-0004, ADR-0006).
 *
 * Emission is gate-dependent (decision log: Phase 2 iteration interview):
 * `confirmation_gate: on_payment` types emit at submission — the registrant
 * must be able to pay before anything confirms — while every other gate
 * emits at confirmation, so an exhibitor is invoiced only once approved.
 * The two emission points partition on the gate, so no registration ever
 * emits twice.
 *
 * Scalars only (ADR-0004). `lines` carry the declared tax *class*, not a
 * rate: Commerce resolves the rate and the treatment — reverse charge, §19
 * UStG — at settlement time (ADR-0017, ADR-0030).
 *
 * `registrantUserId` names the payer for the order Commerce creates
 * (ADR-0044): Commerce stores the ULID on the order and never reads this
 * module's tables to find it.
 *
 * `requestKey` is what makes Commerce idempotent, and it is deliberately not
 * the registration ULID: ADR-0036 lets one registration map to N orders over
 * its lifetime (the initial settlement plus one per priced amendment), so
 * "one order per registration" is no longer the invariant. The key is always
 * the ULID of the domain object that requested settlement — the registration
 * itself for the initial request, the `RegistrationAmendment` for an
 * amendment. That makes it deterministic across replays without a sequence.
 */
final readonly class RegistrationSettlementRequested
{
    /**
     * @param  array{
     *     organization_name: string|null,
     *     recipient_name: string|null,
     *     email: string|null,
     *     address_line: string|null,
     *     postal_code: string|null,
     *     city: string|null,
     *     country_code: string|null,
     *     vat_id: string|null,
     * }  $billingParty
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>  $lines
     */
    public function __construct(
        public string $registrationId,
        public string $registrationTypeId,
        public string $eventId,
        public string $brandId,
        public string $registrantUserId,
        public string $settlement,
        public array $billingParty,
        public array $lines,
        public string $priceDisplay,
        public int $termsDays,
        public string $requestedAt,
        public string $requestKey,
    ) {}
}
