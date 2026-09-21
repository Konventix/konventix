<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0048 point 3: Registration owns `RegistrationQuantityReduced`, so
 * Registration tells the registrant their act got smaller
 * (`registration.quantity-reduced`, D-REG-15).
 *
 * The sixth lifecycle mail, and the only one whose subject is not an end
 * state — which is why it says what remains as well as what went. The
 * numbers come off the event rather than the row: the row already carries
 * the new quantity, and re-reading it would tell the recipient nothing
 * about the change they are being told about.
 *
 * `wasPaid` decides whether the refund sentence appears, read off
 * `payment_status` exactly as `MailOnRegistrationCancelled` reads it —
 * Commerce's refund is queued and may not have run yet, and this module
 * never reads an order to find out (ADR-0004).
 */
final class MailOnRegistrationQuantityReduced
{
    public function __construct(private readonly RegistrationMailer $mailer) {}

    public function handle(RegistrationQuantityReduced $event): void
    {
        /** @var Registration|null $registration */
        $registration = Registration::withoutBrandScope()
            // BrandScope hardens to 1=0 on the admin host, and this event is
            // emitted from a console request (`.ai/rules/app-modules-src.md`):
            // withoutBrandScope() on the parent query does not stop the
            // relation query from re-applying it to `RegistrationType`.
            ->with(['registrationType' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->find($event->registrationId);

        if ($registration === null) {
            return;
        }

        $this->mailer->send($registration, 'registration.quantity-reduced', new RegistrationMailData(
            ...$this->mailer->commonFields($registration),
            wasPaid: $registration->payment_status === PaymentStatus::Paid,
            removedUnits: $event->removedUnits,
            newQuantity: $event->newQuantity,
        ));
    }
}
