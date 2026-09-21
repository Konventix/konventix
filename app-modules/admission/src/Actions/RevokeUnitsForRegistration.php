<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Admission\Exceptions\NotEnoughFreeAdmissionsException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;

/**
 * The partial counterpart of `RevokeAdmissionsForRegistration` (D-REG-15):
 * the contractual act did not end, it got smaller, so exactly `$units` of
 * its access rights end — and only ones nobody is using.
 *
 * **Which N, and why it matters.** A reduction is money coming back, so the
 * admissions it takes must be ones the buyer can genuinely still hand over.
 * Candidates are picked in two tiers, cheapest first:
 *
 * 1. **unassigned, unclaimed, unseated** — nobody has been named on it, no
 *    claim link is out for it, no place is bound to it. Taking one of these
 *    away costs nothing and surprises nobody.
 * 2. **unassigned and unseated, but with a live claim link** — the buyer
 *    sent an invitation that has not been accepted. The link is revoked
 *    with the admission (`RevokeClaimLink`), so the invitee's URL stops
 *    working rather than dead-ending on a revoked ticket.
 *
 * Never a candidate: an admission with a holder (`Assigned`, or any row
 * with a `holder_user_id`), one with a row in `admission_seats` — a named
 * place is a promise to a person — and one with an accepted check-in,
 * which is attendance and cannot be un-happened
 * (`RevokeAdmissionsForRegistration`'s rule, restated here rather than
 * shared, because that Action revokes everything free and this one counts).
 *
 * **Refusal, not partial fulfilment.** Fewer than `$units` free admissions
 * throws `NotEnoughFreeAdmissionsException` naming how many there are. The
 * listener above this runs synchronously inside
 * `ReduceRegistrationQuantity`'s transaction, so the throw rolls back the
 * reduction row, the released quota units and the new quantity, and
 * Commerce's queued refund — dispatched `afterCommit` — is never pushed.
 * Revoking three when five were asked for would have sent money back for
 * two tickets that still work.
 *
 * **Idempotent per reduction** (`admissions.reduction_id`), the subtractive
 * twin of `amendment_id`: a replayed event finds its own reduction already
 * stamped and returns the same rows without touching anything. Asking only
 * "are there revoked admissions?" would let a replay take the *next* N
 * free ones.
 *
 * Takes scalars, never Registration's classes — the thin
 * `RevokeUnitsOnRegistrationQuantityReduced` listener unpacks the event, as
 * on every other path in this module (ADR-0004).
 *
 * `withoutBrandScope()` throughout is ADR-0010's named escape hatch: the
 * reduction is made from the operator console, which resolves no brand at
 * all (ADR-0029), and every row is scoped by the registration's own ULID.
 */
final class RevokeUnitsForRegistration
{
    /**
     * Why this admission stopped being valid, in Admission's own
     * vocabulary. *Why the registration shrank* is Registration's reason
     * code and stays there — the door has no use for it.
     */
    public const string REASON_REGISTRATION_QUANTITY_REDUCED = 'registration_quantity_reduced';

    public function __construct(
        private readonly RevokeClaimLink $revokeClaimLink,
    ) {}

    /**
     * @return list<Admission> the admissions actually revoked
     */
    public function handle(
        string $registrationId,
        string $reductionId,
        int $units,
        ?string $revokedByUserId = null,
    ): array {
        return DB::transaction(function () use ($registrationId, $reductionId, $units, $revokedByUserId): array {
            /** @var list<Admission> $already */
            $already = Admission::withoutBrandScope()
                ->where('registration_id', $registrationId)
                ->where('reduction_id', $reductionId)
                ->get()
                ->all();

            if ($already !== []) {
                return $already;
            }

            $candidates = $this->freeCandidates($registrationId);

            if (count($candidates) < $units) {
                throw NotEnoughFreeAdmissionsException::forRegistration(
                    $registrationId,
                    $units,
                    count($candidates),
                );
            }

            $revoked = [];

            foreach (array_slice($candidates, 0, $units) as $admission) {
                $this->revokeLiveClaimLinksFor($admission);

                $admission->forceFill([
                    'state' => AdmissionState::Revoked,
                    'reduction_id' => $reductionId,
                ])->save();

                event(new AdmissionRevoked(
                    admissionId: $admission->id,
                    registrationId: $admission->registration_id,
                    eventId: $admission->event_id,
                    brandId: $admission->brand_id,
                    revokedByUserId: $revokedByUserId,
                    reasonCode: self::REASON_REGISTRATION_QUANTITY_REDUCED,
                    revokedAt: now()->toIso8601String(),
                ));

                // ADR-0033's negative decision: a signed token can only say
                // yes, so every door has to be told each "no" separately —
                // one delta row per revoked admission, exactly as the whole
                // cancellation writes them.
                $entry = new EntranceDeltaEntry([
                    'event_id' => $admission->event_id,
                    'admission_id' => $admission->id,
                    'kind' => EntranceDeltaEntry::KIND_REVOKED,
                ]);

                // brand_id off the admission row, not ambient context.
                $entry->forceFill(['brand_id' => $admission->brand_id])->save();

                $revoked[] = $admission;
            }

            return $revoked;
        });
    }

    /**
     * Every admission of this registration that can still be handed back,
     * tier 1 (no claim link out) before tier 2 (a live link, revoked with
     * it). Ordered by `id` inside each tier so two runs of the same
     * reduction pick the same rows.
     *
     * @return list<Admission>
     */
    private function freeCandidates(string $registrationId): array
    {
        $admissions = Admission::withoutBrandScope()
            ->where('registration_id', $registrationId)
            // `Assigned` is excluded by the state filter and
            // `holder_user_id` by the column: a row can hold a person
            // without having moved state yet, and either fact disqualifies.
            ->where('state', AdmissionState::Issued)
            ->whereNull('holder_user_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($admissions->isEmpty()) {
            return [];
        }

        $ids = $admissions->modelKeys();

        $seated = AdmissionSeat::withoutBrandScope()
            ->whereIn('admission_id', $ids)
            ->pluck('admission_id')
            ->all();

        $attended = CheckIn::withoutBrandScope()
            ->whereIn('admission_id', $ids)
            ->where('result', CheckInResult::Accepted)
            ->pluck('admission_id')
            ->all();

        $linked = $this->admissionIdsWithLiveClaimLink($ids);

        $plain = [];
        $withLink = [];

        foreach ($admissions as $admission) {
            if (in_array($admission->id, $seated, true) || in_array($admission->id, $attended, true)) {
                continue;
            }

            if (in_array($admission->id, $linked, true)) {
                $withLink[] = $admission;

                continue;
            }

            $plain[] = $admission;
        }

        return [...$plain, ...$withLink];
    }

    /**
     * `AdmissionClaimLink::isClaimable()`'s three conditions as a query —
     * the model method answers for one loaded row, and this needs the set.
     *
     * @param  array<int, int|string>  $admissionIds
     * @return list<string>
     */
    private function admissionIdsWithLiveClaimLink(array $admissionIds): array
    {
        /** @var list<string> $ids */
        $ids = AdmissionClaimLink::withoutBrandScope()
            ->whereIn('admission_id', $admissionIds)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->pluck('admission_id')
            ->all();

        return $ids;
    }

    /**
     * Withdraws whatever invitation is still out for this admission (#292
     * §4.1) before the admission itself goes. `RevokeClaimLink` is
     * idempotent on a link that is no longer claimable, so a link that
     * expired between the selection above and here costs nothing.
     */
    private function revokeLiveClaimLinksFor(Admission $admission): void
    {
        $links = AdmissionClaimLink::withoutBrandScope()
            ->where('admission_id', $admission->id)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->get();

        foreach ($links as $link) {
            $this->revokeClaimLink->handle($link);
        }
    }
}
