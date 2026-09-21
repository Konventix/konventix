<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Registration\Attendees\PublicAttendees;
use Konventix\Registration\Data\AttendeeData;
use Konventix\Registration\Data\EventAttendeesData;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registrant;

/**
 * The attendees page behind the landing-page card (ADR-0064 §7): every
 * name the viewer may see, paginated, on the brand host.
 *
 * Addressed by the event's slug and resolved through this module's own
 * `EventSummary` — never Event's model (ADR-0004). It answers 404 before it
 * answers "sign in": an event the landing page would 404 on, or one whose
 * switch is `off`, does not exist as far as a guest is told, so a login
 * redirect cannot reveal it. Only a `members` list on a visible event asks
 * a guest to sign in.
 */
final class AttendeesController
{
    public function index(Request $request, string $slug): Response|RedirectResponse
    {
        /** @var EventSummary $summary */
        $summary = EventSummary::query()
            ->where('slug', $slug)
            ->visibleOnLanding()
            ->firstOrFail();

        abort_unless($summary->showsAttendeeList(), 404);

        $signedIn = $request->user() !== null;

        if (! $summary->attendeeNamesVisibleTo($signedIn)) {
            return redirect()->guest(route('login'));
        }

        $linkable = PublicAttendees::profileLinksActive();

        return Inertia::render('event/Attendees', [
            'listing' => EventAttendeesData::ofSummary($summary, viewerSignedIn: $signedIn, names: 0),
            'attendees' => PublicAttendees::named($summary->event_id)
                ->paginate(PublicAttendees::PAGE_SIZE)
                ->withQueryString()
                ->through(fn (Registrant $registrant): array => AttendeeData::ofRegistrant($registrant, $linkable)->toArray()),
        ]);
    }
}
