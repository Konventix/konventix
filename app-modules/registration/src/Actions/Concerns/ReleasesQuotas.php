<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Models\Registration;

/**
 * Shared by `RejectRegistration` and `CancelRegistration`: both give back
 * the one unit each quota referenced by the registration's type gave up at
 * submission time (`SubmitRegistration::consumeQuotas()`), and both emit
 * `QuotaReleased` per quota (SPEC.md "Public contract" table).
 */
trait ReleasesQuotas
{
    private function releaseQuotas(Registration $registration): void
    {
        // Explicit loadMissing(): Model::shouldBeStrict() (non-production)
        // forbids lazy-loading an unloaded relation through the magic
        // property, and a caller here only ever has the registration's ID.
        $quotas = $registration->loadMissing('registrationType')
            ->registrationType
            ->quotas()
            ->lockForUpdate()
            ->get();

        foreach ($quotas as $quota) {
            $quota->release(1);

            event(new QuotaReleased(
                quotaId: $quota->id,
                releasedUnits: 1,
                releasedAt: now()->toIso8601String(),
            ));
        }
    }
}
