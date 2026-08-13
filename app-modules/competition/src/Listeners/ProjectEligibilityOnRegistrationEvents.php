<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Competition\Actions\ProjectEligibleRegistration;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * Feeds Competition's entry-eligibility read model (D-CMP-05).
 *
 * Both Registration events are needed: submission carries who the registrant
 * is, confirmation carries that they are entitled. Neither alone answers "may
 * this person enter".
 *
 * Deliberately **not** short-circuited on the Pennant gate, unlike an Action
 * that creates something: a brand that switches Competition on mid-event must
 * find its existing registrations already projected, or nobody could enter
 * anything until they all re-confirmed. Projections stay current even while
 * the feature they serve is off — the same reasoning as Resources' event
 * windows.
 *
 * These are `Registration\Events\` imports, the sanctioned cross-module
 * dependency (ADR-0004).
 */
final class ProjectEligibilityOnRegistrationEvents
{
    public function __construct(
        private readonly ProjectEligibleRegistration $action,
    ) {}

    public function handleSubmitted(RegistrationSubmitted $event): void
    {
        $this->action->submitted(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            userId: $event->registrantUserId,
        );
    }

    public function handleConfirmed(RegistrationConfirmed $event): void
    {
        $this->action->confirmed(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
        );
    }
}
