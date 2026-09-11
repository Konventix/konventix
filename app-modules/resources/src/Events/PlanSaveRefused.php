<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * `SavePlan` refused a save before writing anything (design §6.1 steps
 * 0–4): `stale` for a wrong `expectedVersion`, `invalid` for a document
 * that fails the structural invariants or names a foreign id, `conflict`
 * for an occupied place removed, recategorised or blocked without a
 * resolution.
 *
 * Internal to Resources — an observability signal, not part of the
 * cross-module contract (SPEC.md "Internal events"). Dispatched after the
 * exception is caught and before it is rethrown, outside any transaction:
 * the refusing steps are read-only, the save transaction (step 6) has not
 * started. Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class PlanSaveRefused
{
    public function __construct(
        public string $planId,
        public string $eventId,
        public string $brandId,
        public string $outcome,
    ) {}
}
