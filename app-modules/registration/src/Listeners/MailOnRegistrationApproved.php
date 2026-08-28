<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Events\RegistrationApproved;
use Konventix\Registration\Listeners\Concerns\ComputesNextStepCode;
use Konventix\Registration\Mail\RegistrationMailer;
use Konventix\Registration\Models\Registration;

/**
 * ADR-0048 point 3: Registration owns `RegistrationApproved`, so
 * Registration tells the registrant a reviewer let them through — and, as
 * on submission, what is still left to do (`registration.approved`).
 *
 * `RegistrationApproved`'s payload carries only `registrationId`
 * (SPEC.md "Public contract"), so this re-reads the registration the same
 * way `MailOnRegistrationSubmitted` does.
 */
final class MailOnRegistrationApproved
{
    use ComputesNextStepCode;

    public function __construct(private readonly RegistrationMailer $mailer) {}

    public function handle(RegistrationApproved $event): void
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

        $this->mailer->send($registration, 'registration.approved', new RegistrationMailData(
            ...$this->mailer->commonFields($registration),
            nextStep: $this->nextStepCode($registration, $registration->registrationType),
        ));
    }
}
