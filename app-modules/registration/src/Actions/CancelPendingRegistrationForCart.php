<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Konventix\Registration\Enums\CartHoldEnded;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;

/**
 * A cart line that dies — expired past its reservation, or removed by the
 * person shopping and never taken back — tells Registration to give up the
 * registration it was holding a spot for (ADR-0047 point 3), the same shape
 * `ExpireWaitlistOffers` already applies to an unclaimed offer.
 *
 * Takes scalars and this module's own enum, never a Commerce event or
 * model: the listener that calls this is the one place allowed to import
 * `Konventix\Commerce\Events\*` (ADR-0004).
 *
 * **The reason comes from the trigger (#220).** It used to be hard-coded
 * `cart_expired` however the line had died, and the listener never passed
 * the event type down — so a buyer who removed a line from a cart that was
 * still valid for another twenty minutes read "your reservation expired" in
 * the cancellation mail. `CartHoldEnded` is now a required argument — no default, so no
 * caller can mis-report a removal as an expiry by omission: the
 * listener maps `CartLineExpired` to `Expired` and `CartLineRemoved` to
 * `Removed`, its value is the `reason_code` written on the registration,
 * and `MailOnRegistrationCancelled` branches the mail on it.
 *
 * **A `confirmed` registration is left standing (#211, operator decision
 * 2026-09-22).** The guards used to be `payment_status !== Pending` and
 * "already cancelled", which quietly voided a confirmed registration whose
 * immediate payment happened to sit in a cart (ADR-0047 point 3) twenty
 * minutes after the buyer confirmed it. ADR-0057 point 5 says the opposite
 * in as many words: a confirmed registration stands until its deadline and
 * its `payment_status` tells the truth — the two fields are independent
 * (ADR-0005) precisely so that a lapsed payment window does not throw a
 * confirmed person out. Submitted and approved registrations keep expiring
 * as they always did. The case is logged and counted rather than passed
 * over in silence: it means somebody holds a seat and owes money, which is
 * a receivable for the operator to chase, not an error.
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

    public function handle(string $brandId, string $registrationId, CartHoldEnded $reason): void
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

        if ($registration->status === RegistrationStatus::Confirmed) {
            $this->reportStandingConfirmation($registration, $reason);

            return;
        }

        $this->cancelRegistration->handle(
            $registration,
            cancelledByUserId: $registration->registrant_user_id,
            reasonCode: $reason->value,
        );
    }

    /**
     * How an operator learns that a confirmed seat outlived its cart hold
     * (#211's "geloggt und dem Operator gemeldet").
     *
     * A log line with the ids, and the counter this module already uses to
     * put an operational fact in front of an operator — Registration has a
     * metric catalogue (`Metrics\RegistrationMetrics`) and a panel on the
     * Sale dashboard, and no operator-notice topic of its own; inventing a
     * `registration.operations` template and topic to carry one fact would
     * be a new channel where an existing one answers the question. The
     * counter is recorded here rather than from a listener in
     * `Listeners/Metrics/` for the reason `ExpireCarts` gives for its own
     * operator notice: this is not an event anybody else needs, so it is
     * written where the fact is known instead of being announced first
     * (`Jobs\AdmitWaitingRoomBatch` and `MailQuota` record the same way).
     *
     * No alert rule. Nothing fails silently here — the registration is
     * exactly where the operator would want it, the money is an ordinary
     * open receivable, and a page that fires because somebody has not paid
     * yet is a pager nobody would keep. The panel says how often it
     * happens; the dunning ladder (ADR-0057 point 4) is what chases it.
     *
     * `BrandSlugs::of()` and not the brand id: a label is a bounded set
     * (`.ai/rules/observability.md`), and `Metrics` swallows a storage
     * failure and reports it, so nothing here can abort the sweep that
     * called it.
     */
    private function reportStandingConfirmation(Registration $registration, CartHoldEnded $reason): void
    {
        Log::warning('A confirmed registration outlived the cart line holding its payment; left standing (#211).', [
            'registration_id' => $registration->id,
            'brand_id' => $registration->brand_id,
            'registration_type_id' => $registration->registration_type_id,
            'trigger' => $reason->value,
        ]);

        app(Metrics::class)->increment('confirmed_registrations_kept_total', [
            'brand' => BrandSlugs::of($registration->brand_id),
            'trigger' => $reason->value,
        ]);
    }
}
