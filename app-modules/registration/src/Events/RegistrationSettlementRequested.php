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
 * `saleStartsAt`/`saleEndsAt` are the type's sales window (#218, #216 draft
 * i) as nullable ISO-8601 instants. They are here because Commerce enforces
 * the window for a fresh sale and may not read this module's tables to learn
 * it (ADR-0004). Instants rather than wall-clock times plus a zone, because
 * Commerce could not resolve a zone it never sees (the registration-side
 * migration says why a sales window is not an ADR-0027 event time).
 *
 * **Null on either field means "not window-bound" — not "no bound this
 * side".** The window travels only with a request Commerce may refuse: a
 * fresh registration someone is buying now. A waitlist conversion, a
 * confirmation after approval and a priced amendment all send both fields
 * null, because Registration has already decided those may settle whatever
 * the clock says (`RequestSettlement::$enforcesSalesWindow`). Registration is
 * the authority on who may register.
 *
 * They are appended last with null defaults, so every existing
 * named-argument emission keeps working and an old queued payload replays as
 * "not window-bound" rather than as a refusal.
 *
 * `bindingContext` is an opaque bag of scalars (ADR-0066 §7.2, D-CPL-06):
 * the event, the registration type and the entry conditions this purchase is
 * under. Commerce persists it on the order line and **interprets no key of
 * it** — it exists so that the checkout's consent slot can resolve the texts
 * bound to what is actually being bought, and Compliance is the only module
 * that knows what any of the ids mean. Registration builds it without
 * knowing that a policy exists.
 *
 * The keys are snake_case and the type id travels as a **list of one**,
 * because the bag's shape is the resolver's own
 * (`Compliance\Bindings\BindingContext::ofArray()`): a bag nobody in the
 * middle interprets must arrive in the shape its reader expects, or a
 * translation step would have to live in the module that is forbidden to
 * understand it.
 *
 * Appended with an empty default, so an old queued payload replays as "no
 * context", which resolves unbound policies only — today's behaviour, and
 * the same thing the deliberate absence of a backfill produces.
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
        public ?string $saleStartsAt = null,
        public ?string $saleEndsAt = null,
        /** @var array<string, mixed> */
        public array $bindingContext = [],
    ) {}
}
