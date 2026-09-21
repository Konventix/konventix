<?php

declare(strict_types=1);

namespace Konventix\Registration\Attendees;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;

/**
 * The one answer to "who may the public be told is coming" (ADR-0064). The
 * `event.landing` card and the attendees page both read it, so the count on
 * the card and the list behind it cannot drift — the same reason
 * `Offers\PublicOffers` exists.
 *
 * The rule: confirmed registrations only (§4 — `Submitted` and `Approved`
 * are promises, and payment status is deliberately not consulted), one
 * entry per person (§2 — a person with two registrations is one attendee),
 * and a *name* only for a registrant who opted in and whose handle this
 * module has been told (§3, §6). Names are ordered by username so the list
 * says nothing about who registered first.
 *
 * Whether the *event* may show a list at all is `EventSummary`'s question
 * (`showsAttendeeList()`), answered by the caller before this class is
 * asked anything — the same split `PublicOffers` has with the landing
 * page's own visibility rule.
 */
final class PublicAttendees
{
    /** How many names the landing-page card shows before it links on. */
    public const int CARD_NAMES = 12;

    /** Names per page on the attendees page. */
    public const int PAGE_SIZE = 60;

    /**
     * Everyone who counts: confirmed, one per person.
     */
    public static function count(string $eventId): int
    {
        return self::confirmed($eventId)->distinct()->count('registrant_user_id');
    }

    /**
     * Everyone who may be named, by username.
     *
     * @return Builder<Registrant>
     */
    public static function named(string $eventId): Builder
    {
        return Registrant::query()
            ->whereIn('id', self::confirmed($eventId)->select('registrant_user_id'))
            ->where('listed', true)
            ->whereNotNull('username')
            ->orderBy('username');
    }

    /**
     * ADR-0064 §5: a name links to the person's page only where Community
     * is active for the brand — the same gate the topbar honours. Resolved
     * per call, never cached: `ModuleGate` reads the request's brand.
     */
    public static function profileLinksActive(): bool
    {
        return app(ModuleGate::class)->isActive('community');
    }

    /**
     * @return Builder<Registration>
     */
    private static function confirmed(string $eventId): Builder
    {
        return Registration::query()
            ->where('event_id', $eventId)
            ->where('status', RegistrationStatus::Confirmed);
    }
}
