<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Actions\Concerns\ReleasesQuotas;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Registration\Exceptions\QuantityReductionRefusedException;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationQuantityReduction;

/**
 * Partial cancellation (D-REG-15): the buyer of three double tickets keeps
 * one and gives two back. The registration stays confirmed, its `quantity`
 * drops by `$units`, exactly `$units` quota units return to every quota the
 * type draws on, and the money for those units follows.
 *
 * **Operator-only, and Registration goes first.** ADR-0004 forbids Commerce
 * calling a Registration Action, so the console action lives here and
 * Commerce reacts to `RegistrationQuantityReduced` — the same direction
 * `RegistrationSettlementRequested` already runs in.
 *
 * **Zero is not a reduction and neither is everything.** `1 <= N < quantity`:
 * removing the last unit is a cancellation, and `CancelRegistration` already
 * does that job properly (status, admissions, order, waitlist). This Action
 * refuses rather than emulating it badly.
 *
 * **`amendedUnits()` is deliberately untouched.** A registration's quantity
 * and its amendments are two different sources of held units
 * (`Concerns\ReleasesQuotas`): this Action gives back `$units` of the
 * former and leaves every amendment's own admissions standing, so a later
 * cancellation still releases exactly what is left — the remaining
 * `quantity` plus every amended unit. Releasing here and again at
 * cancellation is the capacity leak this arithmetic exists to prevent.
 *
 * `releaseQuota()` runs under the same `lockForUpdate()` the rest of the
 * release path takes, and `QuotaReleased` per quota is what
 * `PromoteOnQuotaReleased` turns into waitlist offers — N released units
 * make N one-unit offers, because the waitlist deliberately stays one unit
 * per entry (D-REG-12).
 *
 * ## Why Admission can veto a refund it never hears about
 *
 * The operator decision is that a reduction must not return money for a
 * ticket that has already been used, and Admission is the only module that
 * knows. The ordering is carried entirely by listener semantics, so this
 * Action asks Admission nothing:
 *
 * - `RegistrationQuantityReduced` is emitted **inside** this transaction.
 * - Admission's `RevokeUnitsOnRegistrationQuantityReduced` is a plain
 *   synchronous listener. It runs during `event()`, in this transaction,
 *   and throws `NotEnoughFreeAdmissionsException` when fewer than `$units`
 *   admissions are free (unassigned, unclaimed, unseated, never checked
 *   in). The throw propagates out of `event()`, out of `DB::transaction()`,
 *   and the quota release, the new `quantity` and the reduction row all
 *   roll back with it.
 * - Commerce's `RefundUnitsOnRegistrationQuantityReduced` is a **queued**
 *   listener with `$afterCommit = true`. Handling it never happens during
 *   dispatch — only a job push does, and `afterCommit` hands that push to
 *   the transaction manager, which drops it outright when the transaction
 *   rolls back. So the refusal above reaches Commerce as "nothing was ever
 *   dispatched", whichever order the dispatcher happens to hold the two
 *   listeners in.
 *
 * That is why the refusal needs no "refused" state on the reduction row:
 * there is no row. Nothing happened at all, which is the only outcome an
 * operator can act on without reconciling anything by hand.
 */
final class ReduceRegistrationQuantity
{
    use ReleasesQuotas;

    public function handle(
        Registration $registration,
        int $units,
        string $reasonCode,
        ?string $reasonNote = null,
        ?string $reducedByUserId = null,
    ): RegistrationQuantityReduction {
        return DB::transaction(function () use ($registration, $units, $reasonCode, $reasonNote, $reducedByUserId): RegistrationQuantityReduction {
            $this->guard($registration, $units);

            $previousQuantity = $registration->quantity;
            $registrationType = $this->registrationTypeOf($registration);

            // The same read `ReleasesQuotas::releaseQuotas()` performs, and
            // for the same reasons: the type is loaded through the trait
            // (the admin host hardens BrandScope to zero rows) and the
            // quotas escape the scope on their own query, because a
            // relation call re-applies it to the *related* model (#236).
            $quotas = $registrationType->quotas()
                ->withoutGlobalScope(BrandScope::class)
                ->lockForUpdate()
                ->get();

            foreach ($quotas as $quota) {
                // One unit at a time, not one release of N. `QuotaReleased`
                // is what `PromoteOnQuotaReleased` turns into a waitlist
                // offer, and `PromoteFromWaitlist` offers **one** entry per
                // event — the waitlist deliberately stays one unit per
                // entry (D-REG-12), so a single release of N would free
                // three seats and let exactly one person in. N releases of
                // one make N offers, which is what the freed capacity is
                // actually worth.
                for ($unit = 0; $unit < $units; $unit++) {
                    // Re-read between releases. `PromoteOnQuotaReleased`
                    // runs synchronously on the event the previous
                    // iteration emitted and consumes the unit it just
                    // freed — on *its* copy of this row. Releasing again
                    // from the stale instance would write the old
                    // `consumed` back and hand the same seat out twice.
                    $quota->refresh();

                    $this->releaseQuota($quota, 1);
                }
            }

            $registration->forceFill(['quantity' => $previousQuantity - $units])->save();

            $reduction = new RegistrationQuantityReduction([
                'registration_id' => $registration->id,
                'units' => $units,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'reduced_by_user_id' => $reducedByUserId,
                'reduced_at' => now(),
            ]);
            // brand_id off the registration, not ambient context (ADR-0010):
            // the console host resolves no brand at all (ADR-0029).
            $reduction->forceFill(['brand_id' => $registration->brand_id])->save();

            event(new RegistrationQuantityReduced(
                registrationId: $registration->id,
                reductionId: $reduction->id,
                registrationTypeId: $registrationType->id,
                eventId: $registration->event_id,
                brandId: $registration->brand_id,
                registrantUserId: $registration->registrant_user_id,
                removedUnits: $units,
                previousQuantity: $previousQuantity,
                newQuantity: $registration->quantity,
                // Multiplied exactly here, the mirror of
                // `ConfirmRegistration`'s payload: two units of a double
                // ticket reach Admission as four admissions to revoke.
                removedGrants: array_values($registrationType->grants
                    ->toCollection()
                    ->map(fn (GrantData $grant): array => [
                        ...$grant->toEventPayload(),
                        'quantity' => $grant->quantity * $units,
                    ])
                    ->all()),
                reasonCode: $reasonCode,
                reducedByUserId: $reducedByUserId,
                reducedAt: $reduction->reduced_at->toIso8601String(),
            ));

            return $reduction;
        });
    }

    private function guard(Registration $registration, int $units): void
    {
        if ($registration->status !== RegistrationStatus::Confirmed) {
            throw QuantityReductionRefusedException::notConfirmed($registration->id);
        }

        if ($units < 1) {
            throw QuantityReductionRefusedException::notPositive();
        }

        if ($units >= $registration->quantity) {
            throw QuantityReductionRefusedException::wholeRegistration($units, $registration->quantity);
        }
    }
}
