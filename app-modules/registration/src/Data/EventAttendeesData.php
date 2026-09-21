<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Attendees\PublicAttendees;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registrant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The attendee-list card on the event landing page and the header of the
 * attendees page (ADR-0064): the count, which every visitor may see once the
 * event's switch is on (§2), and — only when the switch and the viewer
 * allow it — the first names, with the total behind the link.
 *
 * `namesVisible` is the viewer-dependent half of the event's switch:
 * `public` shows names to anyone, `members` to a signed-in account only,
 * and the card says "sign in to see who is coming" rather than pretending
 * nobody opted in. `attendees` is empty whenever `namesVisible` is false,
 * so the payload never carries a name the viewer may not see.
 */
#[TypeScript]
final class EventAttendeesData extends Data
{
    public function __construct(
        public readonly string $eventName,
        /** The landing page, relative — a link back for the attendees page. */
        public readonly string $eventUrl,
        /** The attendees page, relative. */
        public readonly string $url,
        /** `members` or `public`; never `off`, because then there is no card. */
        public readonly string $visibility,
        public readonly int $count,
        public readonly bool $namesVisible,
        /** How many names the viewer may see in total; the page paginates them. */
        public readonly int $namedCount,
        /** @var list<AttendeeData> */
        public readonly array $attendees,
    ) {}

    /**
     * Named `of*` rather than `from*` on purpose (.ai/rules/data.md).
     *
     * @param  int  $names  How many names to carry — the card's dozen, or
     *                      none for the page, which paginates its own.
     */
    public static function ofSummary(EventSummary $summary, bool $viewerSignedIn, int $names = PublicAttendees::CARD_NAMES): self
    {
        $namesVisible = $summary->attendeeNamesVisibleTo($viewerSignedIn);
        $named = $namesVisible ? PublicAttendees::named($summary->event_id) : null;
        $linkable = $namesVisible && $names > 0 && PublicAttendees::profileLinksActive();

        return new self(
            eventName: $summary->name,
            eventUrl: route('event.show', ['event' => $summary->slug], absolute: false),
            url: route('registration.attendees.index', ['slug' => $summary->slug], absolute: false),
            visibility: $summary->attendee_list_visibility,
            count: PublicAttendees::count($summary->event_id),
            namesVisible: $namesVisible,
            namedCount: $named === null ? 0 : (clone $named)->count(),
            attendees: $named === null || $names === 0
                ? []
                : array_values($named->limit($names)
                    ->get()
                    ->map(fn (Registrant $registrant): AttendeeData => AttendeeData::ofRegistrant($registrant, $linkable))
                    ->all()),
        );
    }
}
