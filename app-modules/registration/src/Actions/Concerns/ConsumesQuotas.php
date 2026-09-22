<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Support\Collection;
use Konventix\Registration\Events\QuotaExhausted;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

/**
 * Shared by `SubmitRegistration` (one unit per submission) and
 * `AmendRegistration` (one unit per added admission — ADR-0036 amendments
 * consume capacity like any other materialization, so a sold-out quota
 * rejects the amendment instead of silently overbooking the venue).
 *
 * All-or-nothing under `lockForUpdate()`: every quota the type references is
 * checked before any is consumed, so a partial consume can never leave the
 * ledger short when a later quota turns out to be exhausted.
 *
 * The mirror image is `ReleasesQuotas`, which must give back the same number
 * of units — see its note on amendments.
 *
 * `consumeQuota()` is the per-quota body `consumeQuotas()` loops over,
 * pulled out so `Listeners\AdjustQuotaOnPlaceSupplyChanged` (design §12b,
 * #282: a block or an operator reservation consumes a unit "as if it had
 * been sold") can reuse the same lock-then-mutate-then-emit path for the
 * single quota a category names, without the all-or-nothing capacity
 * pre-check above — a supply-side change is never refused for exhaustion,
 * it just makes the ledger honest.
 */
trait ConsumesQuotas
{
    private function consumeQuotas(RegistrationType $registrationType, int $units = 1): void
    {
        // In `quotas.id` order, declared on `RegistrationType::quotas()`
        // (#233): two concurrent submissions on two types sharing two
        // quotas used to lock them in opposite pivot-scan orders.
        /** @var Collection<int, Quota> $quotas */
        $quotas = $registrationType->quotas()->lockForUpdate()->get();

        foreach ($quotas as $quota) {
            if (! $quota->hasCapacityFor($units)) {
                throw QuotaExhaustedException::forQuota($quota);
            }
        }

        foreach ($quotas as $quota) {
            $this->consumeQuota($quota, $units, $registrationType->event_id);
        }
    }

    /**
     * Mutates an already-locked quota and emits `QuotaExhausted` the moment
     * it reaches zero remaining — same effect a sale has, called from
     * wherever the caller's own lock was taken.
     */
    private function consumeQuota(Quota $quota, int $units, string $eventId): void
    {
        $quota->consume($units);

        if ($quota->remaining() === 0) {
            event(new QuotaExhausted(
                quotaId: $quota->id,
                eventId: $eventId,
                brandId: $quota->brand_id,
                exhaustedAt: now()->toIso8601String(),
            ));
        }
    }
}
