<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Registration\Actions\DecideEntryConditionAssertion;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;

/**
 * What the door decided, written back onto the declaration it was checking
 * (ADR-0066 §6.4, D-ADM-06).
 *
 * The direction of this listener is the point. Admission never reads a
 * Registration table and learns of no assertion: it is handed a list of
 * condition keys at confirmation, shows the `document_at_door` ones to
 * whoever is at the entrance, and reports back `{key, outcome}` on its own
 * event. This module is the one that knows what a key means, and it looks it
 * up in its own tables (ADR-0004).
 *
 * **A refusal is a record, never a revocation.** It does not cancel the
 * registration, does not revoke the admission and never reaches Commerce
 * (T-8, ADR-0005) — the door decides access, the platform decides nothing.
 * An operator who wants a check that blocks sets `approval: required` on the
 * registration type, which is decided before the event rather than at the
 * turnstile.
 *
 * **Idempotent per scan.** `RecordScan` is idempotent per `client_scan_id`,
 * so a device replaying its buffer never produces a second
 * `AdmissionCheckedIn` — and should one arrive anyway, an assertion already
 * standing in the state the door is reporting, decided at a door, is left
 * exactly as it is. No second row, no second
 * `RegistrationEntryConditionDecided`, no second tick on the counter.
 *
 * A condition the registration never declared is skipped rather than
 * written: a door may not invent a declaration, and the only way an
 * undeclared condition gets a row is an operator's deliberate waive
 * (D-REG-19).
 *
 * Bindings are resolved in `handle()`, never in a constructor: the
 * dispatcher builds listeners with the container it was constructed with,
 * which under Octane is the worker's rather than the request's
 * (.ai/rules/listeners.md).
 */
final class RecordDoorVerification
{
    private const string OUTCOME_CONFIRMED = 'confirmed';

    private const string OUTCOME_REFUSED = 'refused';

    public function handle(AdmissionCheckedIn $event): void
    {
        if ($event->conditionsChecked === []) {
            return;
        }

        // `withoutBrandScope()` and the payload's own brand: a scan arrives
        // through the entrance API, which authenticates a device rather than
        // resolving a brand host (ADR-0033), so nothing ambient is scoping
        // this query (ADR-0010).
        $registration = Registration::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->whereKey($event->registrationId)
            ->first();

        if ($registration === null) {
            return;
        }

        $decide = app(DecideEntryConditionAssertion::class);

        foreach ($event->conditionsChecked as $checked) {
            $state = $this->stateFor($checked['outcome']);

            if ($state === null) {
                continue;
            }

            $condition = EntryCondition::withoutBrandScope()
                ->where('brand_id', $event->brandId)
                ->where('key', $checked['key'])
                ->first();

            if ($condition === null) {
                continue;
            }

            if (! $this->needsDeciding($registration, $condition, $state)) {
                continue;
            }

            $decide->handle(
                registration: $registration,
                condition: $condition,
                state: $state,
                // Nobody signs in at a door; the device is the actor, and a
                // device is not a subject (ADR-0033). The event's own
                // `checkInId` is what ties the decision to the scan, in the
                // check-in row that already holds it.
                decidedByUserId: null,
                via: EntryConditionDecisionSource::Door,
                reasonCode: $state === EntryConditionAssertionState::Refused
                    ? EntryConditionReasonCode::DocumentMissing
                    : null,
            );
        }
    }

    /**
     * The two outcomes a door reports, and nothing else. An unknown string
     * is dropped rather than guessed at — this column is read back years
     * later and exported to the subject under Art. 15.
     */
    private function stateFor(string $outcome): ?EntryConditionAssertionState
    {
        return match ($outcome) {
            self::OUTCOME_CONFIRMED => EntryConditionAssertionState::Verified,
            self::OUTCOME_REFUSED => EntryConditionAssertionState::Refused,
            default => null,
        };
    }

    /**
     * Whether there is a declaration to decide, and whether the door has not
     * already decided it that way — the idempotency this listener promises.
     */
    private function needsDeciding(
        Registration $registration,
        EntryCondition $condition,
        EntryConditionAssertionState $state,
    ): bool {
        /** @var EntryConditionAssertion|null $assertion */
        $assertion = EntryConditionAssertion::withoutBrandScope()
            ->where('registration_id', $registration->getKey())
            ->where('entry_condition_id', $condition->getKey())
            ->first();

        if ($assertion === null) {
            return false;
        }

        return $assertion->state !== $state
            || $assertion->decided_via !== EntryConditionDecisionSource::Door;
    }
}
