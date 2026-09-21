<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Events\RegistrationEntryConditionDecided;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;
use RuntimeException;

/**
 * Judging one declaration (spec §9.1, D-REG-22): verify it, refuse it, or
 * waive it.
 *
 * Three things this Action deliberately does not do.
 *
 * It does not gate anything. `operator_checked` fills a worklist and never
 * blocks a confirmation — a blocking check is `approval: required` on the
 * type, which ADR-0005 already owns (D-REG-22). A refusal here records a
 * fact and nothing else: it never revokes an admission, never cancels a
 * registration and never reaches Commerce (T-8, ADR-0005).
 *
 * It does not accept a sentence about a person. `reason_code` is one of the
 * bounded codes and nothing else — this column is read back years later and
 * is exported to the subject under Art. 15.
 *
 * And it does not need the assertion to exist. A condition added to a live
 * type after a registration was made has **no** row (D-REG-19): the console
 * shows it as *not asserted*, and the honest operator answer to that is a
 * **waive**, which is a row this Action writes on the spot with
 * `reason_code: operator_waived` and no assertion text anybody ever showed
 * the registrant — `assertion_text_snapshot` then records the wording as it
 * stands now, marked by the waived state rather than pretending to be a
 * declaration.
 *
 * `decided_via` is a parameter rather than a constant because Task 33's door
 * writes the same row from `AdmissionCheckedIn`.
 */
final class DecideEntryConditionAssertion
{
    public function handle(
        Registration $registration,
        EntryCondition $condition,
        EntryConditionAssertionState $state,
        ?string $decidedByUserId,
        EntryConditionDecisionSource $via = EntryConditionDecisionSource::Console,
        ?EntryConditionReasonCode $reasonCode = null,
    ): EntryConditionAssertion {
        if ($state === EntryConditionAssertionState::Asserted) {
            throw new RuntimeException('A decision is verify, refuse or waive — "asserted" is what the registrant wrote.');
        }

        if ($state === EntryConditionAssertionState::Waived) {
            $reasonCode ??= EntryConditionReasonCode::OperatorWaived;
        }

        return DB::transaction(function () use ($registration, $condition, $state, $decidedByUserId, $via, $reasonCode): EntryConditionAssertion {
            /** @var EntryConditionAssertion|null $assertion */
            $assertion = EntryConditionAssertion::withoutBrandScope()
                ->where('registration_id', $registration->getKey())
                ->where('entry_condition_id', $condition->getKey())
                ->lockForUpdate()
                ->first();

            if ($assertion === null) {
                if ($state !== EntryConditionAssertionState::Waived) {
                    throw new RuntimeException('This registration never declared that condition; it can only be waived.');
                }

                $assertion = new EntryConditionAssertion([
                    'registration_id' => $registration->getKey(),
                    'entry_condition_id' => $condition->getKey(),
                    'subject_user_id' => $registration->registrant_user_id,
                    'asserted_at' => now(),
                    'assertion_text_snapshot' => $condition->assertion_text,
                    'verification_snapshot' => $condition->verification,
                    'state' => $state,
                ]);

                $assertion->forceFill(['brand_id' => $registration->brand_id]);
            }

            $assertion->forceFill([
                'state' => $state,
                'decided_by_user_id' => $decidedByUserId,
                'decided_at' => now(),
                'decided_via' => $via,
                'reason_code' => $reasonCode,
            ])->save();

            event(new RegistrationEntryConditionDecided(
                assertionId: (string) $assertion->getKey(),
                registrationId: (string) $registration->getKey(),
                entryConditionKey: $condition->key,
                brandId: $registration->brand_id,
                state: $state->value,
                decidedByUserId: $decidedByUserId,
                decidedVia: $via->value,
                reasonCode: $reasonCode?->value,
                decidedAt: $assertion->decided_at->toIso8601String(),
            ));

            return $assertion;
        });
    }
}
