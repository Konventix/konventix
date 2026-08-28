<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0048 point 3: Registration owns `RegistrationRejected`, so
 * Registration tells the registrant a reviewer declined it
 * (`registration.rejected`). The raw `reasonCode` rides in the payload as
 * `reason` — mapped to a sentence per locale in the template, falling back
 * to the code itself for one this module has not seen before, rather than
 * a PHP-computed sentence that could not know the recipient's locale
 * (`Data\RegistrationMailData`'s docblock).
 *
 * `RegistrationRejected`'s payload carries only `registrationId` beyond the
 * reason, so this re-reads the registration the same way
 * `MailOnRegistrationSubmitted` does.
 */
final class MailOnRegistrationRejected
{
    public function __construct(private readonly RegistrationMailer $mailer) {}

    public function handle(RegistrationRejected $event): void
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

        $this->mailer->send($registration, 'registration.rejected', new RegistrationMailData(
            ...$this->mailer->commonFields($registration),
            reason: $event->reasonCode,
        ));
    }
}
