<?php

declare(strict_types=1);

namespace Konventix\Content\Hub;

use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Content\Data\AnnouncementItemData;
use Konventix\Content\Data\AnnouncementsCardData;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `content.announcements` card (D-HUB-05,
 * design §5.2): what the organizers said about *this* event, newest first.
 *
 * An invokable builder rather than a Laravel Action, for the reason
 * Admission's `MyTicketCard` gives: this answers a question for one
 * render, and the answer's shape is what `HubCard`'s payload closure has
 * to return.
 *
 * `scopeLive()` rather than a second publication rule of its own: an entry
 * is published in one place (state *and* date — a scheduled one is not
 * live until `PublishScheduledEntries` promotes it), and a card that
 * decided it again would eventually disagree with `/news`.
 *
 * News *and* announcements, because the difference between the two is how
 * they are presented, not whether they are addressed to the people at the
 * event. Pages are left out: a page is reference material, not something
 * that happened today.
 *
 * `$user` goes unread — the card says the same thing to every participant
 * of the event. Audience is deliberately not filtered here either: the hub
 * renders only for an admitted member, so `Audience::LoggedIn` is already
 * satisfied by the surface itself.
 */
final class AnnouncementsCard
{
    /** How many fit on a card before it stops being a teaser of `/news`. */
    private const int LIMIT = 3;

    public function __invoke(ActiveEventData $event, Authenticatable $user): AnnouncementsCardData
    {
        $entries = ContentEntry::query()
            ->live()
            ->where('event_id', $event->eventId)
            ->whereIn('type', [ContentType::News, ContentType::Announcement])
            ->orderByDesc('published_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ContentEntry $entry): AnnouncementItemData => new AnnouncementItemData(
                title: $entry->title,
                teaser: $entry->teaser,
                // Relative, like every hub URL: the card renders on the
                // brand's own host and Inertia follows it as a visit.
                url: route('content.show', $entry->slug, absolute: false),
                // Unreachable: `scopeLive()` already asked for a
                // `published_at`. The coalesce is for PHPStan, which does
                // not read a scope's `whereNotNull`, and the empty string
                // is what `formatDateTime()` renders as nothing.
                publishedAt: $entry->published_at?->toIso8601String() ?? '',
            ))
            ->all();

        return new AnnouncementsCardData(
            entries: $entries,
            newsUrl: route('content.index', absolute: false),
        );
    }
}
