<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;

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

        $this->mailer->send($registration, 'registration.confirmed', new RegistrationMailData(
            ...$this->mailer->commonFields($registration),
        ));
    }
}
