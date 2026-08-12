<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;

/**
 * Shared by `RejectRegistration` and `CancelRegistration`: both give back
 * every unit each quota referenced by the registration's type gave up, and
 * both emit `QuotaReleased` per quota (SPEC.md "Public contract" table).
 *
 * That is one unit for the submission itself (`ConsumesQuotas`, called from
 * `SubmitRegistration`) **plus** one per admission any amendment added
 * (ADR-0036). Releasing only the submission's unit would leak capacity
 * permanently on every amended registration — the seats would stay counted
 * as sold after the registration was cancelled.
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

        $units = 1 + $this->amendedUnits($registration);

        foreach ($quotas as $quota) {
            $quota->release($units);

            event(new QuotaReleased(
                quotaId: $quota->id,
                releasedUnits: $units,
                releasedAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * Admission units added by amendments — `withoutBrandScope()` scoped to
     * the registration's own brand (ADR-0010), because a cancellation may be
     * replayed by a queue worker with no ambient brand context.
     */
    private function amendedUnits(Registration $registration): int
    {
        return RegistrationAmendment::withoutBrandScope()
            ->where('brand_id', $registration->brand_id)
            ->where('registration_id', $registration->id)
            ->get()
            ->sum(fn (RegistrationAmendment $amendment): int => $amendment->admissionUnits());
    }
}
