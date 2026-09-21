<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A confirmed registration bought fewer units than it did a moment ago
 * (D-REG-15) — the subtractive counterpart of `RegistrationAmended`, and
 * the only partial cancellation there is. Emitted by
 * `Konventix\Registration\Actions\ReduceRegistrationQuantity`, inside its
 * transaction.
 *
 * `removedGrants` carries **only what is being taken away**, in exactly
 * `RegistrationConfirmed.grants`' shape and already multiplied by
 * `removedUnits` — so Admission reads "revoke six admissions" and Resources
 * reads "six fewer seats" without either of them learning that a
 * registration has a quantity at all. That is the same contract the
 * confirmation carries, which is why a negative `RegistrationAmended` was
 * rejected: it would have broken Admission's per-amendment idempotency and
 * Resources' claim arithmetic at once.
 *
 * `reductionId` is the idempotency key. Admission stamps it on the
 * admissions it revoked, Resources records it against the claim it shrank
 * and Commerce writes it on the credit note, so a replay of this event
 * revokes, releases and refunds nothing twice.
 *
 * **Consumer ordering is part of the contract.** Admission's listener is a
 * plain synchronous one and refuses — by throwing — when fewer than
 * `removedUnits` admissions are free; Commerce's is queued `afterCommit`,
 * so its job is pushed only once the Action's transaction commits. A
 * refusal therefore rolls the whole reduction back and no money moves. See
 * `ReduceRegistrationQuantity`'s docblock for the full argument.
 *
 * Scalars only (ADR-0004).
 *
 * @phpstan-type GrantPayload array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 */
final readonly class RegistrationQuantityReduced
{
    /**
     * @param  list<GrantPayload>  $removedGrants
     */
    public function __construct(
        public string $registrationId,
        public string $reductionId,
        public string $registrationTypeId,
        public string $eventId,
        public string $brandId,
        public string $registrantUserId,
        public int $removedUnits,
        public int $previousQuantity,
        public int $newQuantity,
        public array $removedGrants,
        public string $reasonCode,
        public ?string $reducedByUserId,
        public string $reducedAt,
    ) {}
}
