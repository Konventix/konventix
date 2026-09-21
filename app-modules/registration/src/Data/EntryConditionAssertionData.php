<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the registration detail's entry-condition list (spec §4.2,
 * §9.1) — and the three states that list has to be able to say out loud
 * after D-REG-19 left the set editable:
 *
 * - a **declaration**: `state` is what the registrant wrote and what was
 *   made of it, `assertionText` is the wording they were actually shown.
 * - **not asserted**: `state` is null. A condition added to this type after
 *   this registration existed writes no row at all, and the absence is the
 *   state. It is not an error, it blocks nothing, and the operator may waive
 *   it or ask by hand.
 * - **no longer required**: `stillRequired` is false. The condition left the
 *   type; the historic declaration stays, because it is evidence and not
 *   configuration.
 *
 * `assertionText` is the snapshot where one exists and the condition's
 * current wording where it does not — a row with no declaration has no
 * wording anybody was shown, so the only honest thing to print is what the
 * condition says today.
 */
#[TypeScript]
final class EntryConditionAssertionData extends Data
{
    public function __construct(
        public readonly string $conditionId,
        public readonly string $conditionKey,
        public readonly string $conditionName,
        public readonly string $assertionText,
        public readonly ?string $documentHint,
        public readonly EntryConditionVerification $verification,

        /** Null means *not asserted* — no row exists (D-REG-19). */
        public readonly ?EntryConditionAssertionState $state,

        /** False means the type no longer demands this condition. */
        public readonly bool $stillRequired,

        public readonly ?string $assertedAt = null,
        public readonly ?string $decidedAt = null,
        public readonly ?EntryConditionDecisionSource $decidedVia = null,
        public readonly ?EntryConditionReasonCode $reasonCode = null,
    ) {}

    public static function ofAssertion(
        EntryConditionAssertion $assertion,
        EntryCondition $condition,
        bool $stillRequired,
    ): self {
        return new self(
            conditionId: (string) $condition->getKey(),
            conditionKey: $condition->key,
            conditionName: $condition->name,
            assertionText: $assertion->assertion_text_snapshot,
            documentHint: $condition->document_hint,
            verification: $assertion->verification_snapshot,
            state: $assertion->state,
            stillRequired: $stillRequired,
            assertedAt: $assertion->asserted_at->toIso8601String(),
            decidedAt: $assertion->decided_at?->toIso8601String(),
            decidedVia: $assertion->decided_via,
            reasonCode: $assertion->reason_code,
        );
    }

    /**
     * A condition this type demands that this registration never declared
     * (D-REG-19).
     */
    public static function ofUnasserted(EntryCondition $condition): self
    {
        return new self(
            conditionId: (string) $condition->getKey(),
            conditionKey: $condition->key,
            conditionName: $condition->name,
            assertionText: $condition->assertion_text,
            documentHint: $condition->document_hint,
            verification: $condition->verification,
            state: null,
            stillRequired: true,
        );
    }
}
