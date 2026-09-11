<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registration's buyer, restated for backfill (design doc §13, 2026-09-11
 * amendment "a registration-derived admission is anonymous today").
 * `admissions.buyer_user_id` is threaded from `RegistrationConfirmed` and
 * `RegistrationAmended` going forward (`IssueAdmissionsForRegistration`),
 * but a row written before that change has neither a stored value nor any
 * way to derive one from its own data. Admission's
 * `FillBuyerOnRegistrationOwnershipAnnounced` fills it in.
 *
 * This event exists — rather than `registration:announce-ownership`
 * re-firing `RegistrationConfirmed` itself — precisely so the backfill is
 * replay-safe: `RegistrationConfirmed` also drives
 * `Konventix\Registration\Listeners\MailOnRegistrationConfirmed`, and
 * re-emitting it would re-send every confirmation mail ever sent. This
 * event carries only the three fields a *projection* needs and nothing a
 * mailer, a quota or an invoice could react to. **Only a projection may
 * ever listen to it** — adding a second listener here is the mistake this
 * docblock exists to prevent.
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationOwnershipAnnounced
{
    public function __construct(
        public string $registrationId,
        public string $brandId,
        public string $registrantUserId,
    ) {}
}
