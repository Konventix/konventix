<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;

/**
 * A cart line that dies — expired past its reservation, or removed by the
 * person shopping — tells Registration to give up the registration it was
 * holding a spot for (ADR-0047 point 3), the same shape
 * `ExpireWaitlistOffers` already applies to an unclaimed offer.
 *
 * Takes scalars, never a Commerce event or model: the listener that calls
 * this is the one place allowed to import `Konventix\Commerce\Events\*`
 * (ADR-0004).
 *
 * Idempotent: a registration already paid, or already cancelled, is left
 * alone — a second `CartLineExpired`/`CartLineRemoved` for the same
 * registration (a replay, or both events somehow firing) does nothing on
 * its second run. `withoutBrandScope()` because this runs from a queue
 * worker with no ambient brand context (`app-modules-src.md`).
 */
final class CancelPendingRegistrationForCart
{
    public function __construct(
        private readonly CancelRegistration $cancelRegistration,
    ) {}

    public function handle(string $brandId, string $registrationId): void
    {
        $registration = Registration::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('id', $registrationId)
            ->first();

        if ($registration === null) {
            return;
        }

        if ($registration->payment_status !== PaymentStatus::Pending) {
            return;
        }

        if ($registration->status === RegistrationStatus::Cancelled) {
            return;
        }

        $this->cancelRegistration->handle(
            $registration,
            cancelledByUserId: $registration->registrant_user_id,
            reasonCode: 'cart_expired',
        );
    }
}
