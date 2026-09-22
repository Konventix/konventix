<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Enums\CartHoldEnded;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0048 point 3: Registration owns `RegistrationCancelled`, so
 * Registration tells the registrant their contractual act ended
 * (`registration.cancelled`). `cancelReason` classifies *why*, for the
 * template to pick the right tone (`Data\RegistrationMailData`'s docblock):
 * `cart_expired` when the reservation lapsed before payment,
 * `cart_line_removed` when the buyer took the line out of a cart that was
 * still perfectly valid and let #219's undo window close on it (#220 — the
 * mail used to call that "expired" too, which was simply untrue),
 * `registrant` when the person who registered cancelled it themselves,
 * `operator` otherwise. `wasPaid` tells the template whether to add the
 * refund sentence — read off `payment_status` as it stood at cancellation,
 * since `CancelRegistration` never touches that field itself.
 */
final class MailOnRegistrationCancelled
{
    public function __construct(private readonly RegistrationMailer $mailer) {}

    public function handle(RegistrationCancelled $event): void
    {
        /** @var Registration|null $registration */
        $registration = Registration::withoutBrandScope()
            // BrandScope hardens to 1=0 on the admin host (`.ai/rules/app-modules-src.md`):
            // withoutBrandScope() on the parent query does not stop the
            // relation query from re-applying it to `RegistrationType`.
            ->with(['registrationType' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->find($event->registrationId);

        if ($registration === null) {
            return;
        }

        $this->mailer->send($registration, 'registration.cancelled', new RegistrationMailData(
            ...$this->mailer->commonFields($registration),
            cancelReason: $this->cancelReasonCode($event, $registration),
            wasPaid: $registration->payment_status === PaymentStatus::Paid,
        ));
    }

    private function cancelReasonCode(RegistrationCancelled $event, Registration $registration): string
    {
        return match (true) {
            // The two cart codes are `CartHoldEnded`'s own values, read off
            // the enum rather than re-spelled here: they are written onto
            // the registration by `CancelPendingRegistrationForCart` and a
            // second copy of the string is how the mail and the record
            // drift apart.
            $event->reasonCode === CartHoldEnded::Expired->value => CartHoldEnded::Expired->value,
            $event->reasonCode === CartHoldEnded::Removed->value => CartHoldEnded::Removed->value,
            // A cart-driven cancellation is recorded against the registrant
            // — they are the payer — so both cases above have to be
            // answered before this line, or a removal would read as "you
            // asked us to cancel it".
            $event->cancelledByUserId === $registration->registrant_user_id => 'registrant',
            default => 'operator',
        };
    }
}
