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
 */
trait ConsumesQuotas
{
    private function consumeQuotas(RegistrationType $registrationType, int $units = 1): void
    {
        /** @var Collection<int, Quota> $quotas */
        $quotas = $registrationType->quotas()->lockForUpdate()->get();

        foreach ($quotas as $quota) {
            if (! $quota->hasCapacityFor($units)) {
                throw QuotaExhaustedException::forQuota($quota);
            }
        }

        foreach ($quotas as $quota) {
            $quota->consume($units);

            if ($quota->remaining() === 0) {
                event(new QuotaExhausted(
                    quotaId: $quota->id,
                    eventId: $registrationType->event_id,
                    brandId: $quota->brand_id,
                    exhaustedAt: now()->toIso8601String(),
                ));
            }
        }
    }
}
