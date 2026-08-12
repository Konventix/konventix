<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Actions\UnassignVenue;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

/**
 * Operator console: attach/detach venues on an event (D-EVT-15). The
 * cross-brand guard is explicit: an event may only be assigned venues of
 * its own brand — the two rows both exist outside ambient scope here
 * (ADR-0029), so the invariant is checked, not assumed.
 */
class EventVenueController
{
    public function store(Request $request, string $event, AssignVenue $assignVenue): RedirectResponse
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $validated = $request->validate([
            'venue_id' => ['required', 'ulid', 'exists:venues,id'],
            'is_primary' => ['boolean'],
        ]);

        [$eventModel, $venueModel] = $this->resolvePair($event, $validated['venue_id']);

        $assignVenue->handle($eventModel, $venueModel, (bool) ($validated['is_primary'] ?? false));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue assigned.')]);

        return back();
    }

    public function destroy(string $event, string $venue, UnassignVenue $unassignVenue): RedirectResponse
    {
        Gate::authorize(Permission::UpdateEvent->value);

        [$eventModel, $venueModel] = $this->resolvePair($event, $venue);

        $unassignVenue->handle($eventModel, $venueModel);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue removed.')]);

        return back();
    }

    /**
     * @return array{0: Event, 1: Venue}
     */
    private function resolvePair(string $event, string $venue): array
    {
        /** @var Event $eventModel */
        $eventModel = Event::withoutBrandScope()->findOrFail($event);
        /** @var Venue $venueModel */
        $venueModel = Venue::withoutBrandScope()->findOrFail($venue);

        if ($eventModel->brand_id !== $venueModel->brand_id) {
            throw ValidationException::withMessages([
                'venue_id' => __('This venue belongs to a different brand than the event.'),
            ]);
        }

        return [$eventModel, $venueModel];
    }
}
