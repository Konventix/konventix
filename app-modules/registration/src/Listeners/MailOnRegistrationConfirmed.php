<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Messaging\Data\AttachmentData;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;
use Spatie\LaravelData\DataCollection;

/**
 * ADR-0048 point 3: Registration owns `RegistrationConfirmed` — the one
 * event that materializes entitlements — so Registration tells the
 * registrant their entry is settled and points them at their own
 * registration page for what comes next (`registration.confirmed`).
 *
 * `RegistrationConfirmed`'s payload carries `grants`, but this listener
 * re-reads the registration the same way the other four do: the mail names
 * the type, not a line-by-line grant breakdown that duplicates the "my
 * registrations" page.
 *
 * The re-read is also what makes `cartFields()` work (#210): with the shop
 * on, Commerce has just taken this registration's `instant` settlement into
 * the registrant's cart and `RecordCartHoldOnLinePlaced` has stamped the
 * hold's deadline on the row — `ConfirmRegistration` requests the
 * settlement before it announces the confirmation for exactly this reason.
 * On every other path both fields are null and the mail reads as before.
 */
final class MailOnRegistrationConfirmed
{
    public function __construct(private readonly RegistrationMailer $mailer) {}

    public function handle(RegistrationConfirmed $event): void
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

        $cart = $this->mailer->cartFields($registration);
        $calendarAttachment = $this->mailer->calendarAttachment($registration);

        $this->mailer->send(
            $registration,
            'registration.confirmed',
            new RegistrationMailData(
                ...$this->mailer->commonFields($registration),
                cartUrl: $cart['cartUrl'],
                cartHoldExpiresAt: $cart['cartHoldExpiresAt'],
            ),
            attachments: $calendarAttachment === null ? null : new DataCollection(AttachmentData::class, [$calendarAttachment]),
        );
    }
}
