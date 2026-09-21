<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Compliance\Listeners\Concerns\RecordsResolvedConsents;
use Konventix\Registration\Events\RegistrationConsentGiven;

/**
 * Turns a registrant's declaration on the registration form into Consent
 * rows (ADR-0066 §6.2, D-CPL-10).
 *
 * Registration's half of what `RecordCheckoutConsent` does for a purchase:
 * the module that owns the form says only *that* somebody declared, for
 * which act; which documents that covers is this module's answer, resolved
 * from the binding context the page itself rendered. Registration never
 * learns that a policy exists (ADR-0004), and the resolution is not
 * re-derived from the brand — a text bound to the type being bought must be
 * recorded, and a text bound to some other type must not.
 *
 * `capture_context` stays the coarse label the event carries
 * (`registration`); the binding snapshot beside it is the precise one
 * (D-CPL-09).
 */
final class RecordConsentDeclaration
{
    use RecordsResolvedConsents;

    public function handle(RegistrationConsentGiven $event): void
    {
        $this->recordResolvedConsents(
            $event->brandId,
            $event->subjectId,
            $event->captureContext,
            $event->acceptedAt,
            $event->bindingContext,
        );
    }
}
