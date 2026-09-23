<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Competition\Actions\ProjectEligibleRegistration;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationStandingAnnounced;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * Feeds Competition's entry-eligibility read model (D-CMP-05).
 *
 * Both Registration events are needed: submission carries who the registrant
 * is, confirmation carries that they are entitled. Neither alone answers "may
 * this person enter". A cancellation or a rejection takes the entitlement
 * back (final review, part 2, I1: the refund console cancels, and a refunded
 * guest must not keep entering), and `RegistrationStandingAnnounced` restates
 * a registration whole for the backfill, `registration:announce-standing`.
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

    public function handleCancelled(RegistrationCancelled $event): void
    {
        $this->action->withdrawn($event->registrationId);
    }

    public function handleRejected(RegistrationRejected $event): void
    {
        $this->action->withdrawn($event->registrationId);
    }

    public function handleStandingAnnounced(RegistrationStandingAnnounced $event): void
    {
        $this->action->announced(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            userId: $event->registrantUserId,
            confirmed: $event->confirmed,
        );
    }
}
