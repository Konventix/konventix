<?php

declare(strict_types=1);

namespace Konventix\Resources\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Resources\Actions\AssignPlacesToRegistration;
use Konventix\Resources\Actions\ComputePlanAvailability;
use Konventix\Resources\Actions\FindSeatClaimForRegistrant;
use Konventix\Resources\Data\CategoryData;
use Konventix\Resources\Data\PickPlacesData;
use Konventix\Resources\Data\PlanViewData;
use Konventix\Resources\Data\SeatClaimData;
use Konventix\Resources\Data\SeatPickerData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * The seat picker (design §7.4): the page a confirmed registrant opens from
 * "Meine Registrierung" to choose their places, and the post that saves the
 * choice. Translates HTTP to Data and Actions and nothing more.
 *
 * No `Gate::authorize()` here, deliberately (design §9.1): the picker needs
 * no capability, ownership of the claim is the whole authorisation, and
 * `FindSeatClaimForRegistrant` answers null — a 404, so a stranger learns
 * nothing about whether the registration exists — for anybody else.
 *
 * Every category of the event is sent, not only the claim's: all places
 * render and the ineligible ones say why (design §7.4). Only published
 * plans are sent; availability still names places on unpublished plans as
 * `unavailable` so the map and the plan list cannot disagree.
 *
 * No `noFreePlaces` flag on `SeatPickerData` (2026-09-10 review fix): the
 * page already polls `availability` every 15 s, so `Pick.vue` derives
 * "nothing free" from that live map itself rather than from a boolean
 * this method could only compute once, at the moment of the request.
 *
 * `claim.seatingMode` decides whether the picker itself renders at all
 * (item 1 of the 2026-09-10 fix wave, #278): an `assign` claim is placed
 * by the organiser, never by the registrant, so `Pick.vue` shows the same
 * explanatory state `SeatSummarySlot.vue` shows and never the picker. This
 * method still renders the page for an `assign` claim — the explanatory
 * state is a page, not a 404 — and `store()` refuses a pick against one
 * regardless of what the client sent, since the mode already travelled in
 * `claim.seatingMode` and a bypassed frontend could post anyway.
 */
final class SeatPickController
{
    public function show(
        Request $request,
        string $registration,
        FindSeatClaimForRegistrant $claims,
        ComputePlanAvailability $availability,
    ): Response {
        $claim = $claims->handle($registration, (string) $request->user()->getAuthIdentifier());
        abort_if($claim === null, 404);

        $categories = array_values(ResourceType::query()
            ->where('event_id', $claim->event_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ResourceType $type): CategoryData => CategoryData::fromModel($type))
            ->all());

        $plans = array_values(Plan::query()
            ->where('event_id', $claim->event_id)
            ->whereNotNull('published_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Plan $plan): PlanViewData => PlanViewData::fromModel($plan))
            ->all());

        $currentPlaceIds = array_values(ResourceBooking::query()
            ->where('claim_id', $claim->getKey())
            ->whereIn('state', BookingState::occupyingValues())
            ->orderBy('created_at')
            ->pluck('resource_id')
            ->all());

        $data = new SeatPickerData(
            registrationId: $registration,
            claim: SeatClaimData::fromModel($claim, ResourceEventWindow::forEvent($claim->brand_id, $claim->event_id)),
            categories: $categories,
            plans: $plans,
            availability: $availability->handle($claim),
            currentPlaceIds: $currentPlaceIds,
        );

        return Inertia::render('seating/Pick', $data->toArray());
    }

    /**
     * Ownership before validation, on purpose: a stranger posting garbage
     * gets the same 404 as a stranger reading, not a 422 that confirms the
     * address. The Action throws `ValidationException` on `placeIds` for a
     * refused place, and Inertia carries that back to the form.
     */
    public function store(
        Request $request,
        string $registration,
        FindSeatClaimForRegistrant $claims,
        AssignPlacesToRegistration $assign,
    ): RedirectResponse {
        $userId = (string) $request->user()->getAuthIdentifier();
        $claim = $claims->handle($registration, $userId);
        abort_if($claim === null, 404);

        if ($claim->seating_mode === SeatingMode::Assign) {
            throw ValidationException::withMessages([
                'placeIds' => __('Your seat will be assigned by the organiser.'),
            ]);
        }

        $data = PickPlacesData::validateAndCreate($request);

        $assign->handle($claim, $data->placeIds, AssignPlacesToRegistration::ACTOR_REGISTRANT, $userId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your seat is saved.')]);

        return to_route('registration.mine.show', ['registration' => $registration]);
    }
}
