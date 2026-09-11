<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Models\Quota;
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
 *
 * `releaseQuota()` is the per-quota body `releaseQuotas()` loops over,
 * pulled out for the same reason `ConsumesQuotas::consumeQuota()` is:
 * `Listeners\AdjustQuotaOnPlaceSupplyChanged` (design §12b, #282) reuses it
 * to give back the unit an unblock or a released operator reservation
 * frees, on the single quota its own lock already found.
 */
trait ReleasesQuotas
{
    // Both reads below happen on a path a Commerce listener may drive from
    // the operator admin host, where BrandScope hardens to zero rows — #236.
    use LoadsRegistrationType;

    private function releaseQuotas(Registration $registration): void
    {
        // Explicit load: Model::shouldBeStrict() (non-production) forbids
        // lazy-loading an unloaded relation through the magic property, and
        // a caller here only ever has the registration's ID. The type is
        // read through `LoadsRegistrationType` and the quotas escape
        // BrandScope on their own query: a relation call re-applies the
        // scope on the *related* model, so `withoutBrandScope()` on the
        // registration would still have read an empty set here (#236,
        // `.ai/rules/app-modules-src.md`).
        $quotas = $this->registrationTypeOf($registration)
            ->quotas()
            ->withoutGlobalScope(BrandScope::class)
            ->lockForUpdate()
            ->get();

        $units = 1 + $this->amendedUnits($registration);

        foreach ($quotas as $quota) {
            $this->releaseQuota($quota, $units);
        }
    }

    /**
     * Mutates an already-locked quota and emits `QuotaReleased`, which
     * `PromoteOnQuotaReleased` already turns into a waitlist offer — called
     * from wherever the caller's own lock was taken.
     */
    private function releaseQuota(Quota $quota, int $units): void
    {
        $quota->release($units);

        event(new QuotaReleased(
            quotaId: $quota->id,
            releasedUnits: $units,
            releasedAt: now()->toIso8601String(),
        ));
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
