<?php

declare(strict_types=1);

namespace Konventix\Resources\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Resources\Actions\AssignPlaceToAdmission;
use Konventix\Resources\Actions\ComputePlanAvailability;
use Konventix\Resources\Actions\FindSeatClaimForAdmission;
use Konventix\Resources\Data\CategoryData;
use Konventix\Resources\Data\PickPlacesData;
use Konventix\Resources\Data\PlanViewData;
use Konventix\Resources\Data\SeatClaimData;
use Konventix\Resources\Data\SeatPickerData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceType;

/**
 * The seat picker per ticket (#292 §6.4): the same `seating/Pick` page
 * `SeatPickController` renders for a registration, opened for one
 * admission — one place at most, chosen by the holder or the buyer who
 * manages the group. Translates HTTP to Data and Actions and nothing more.
 *
 * No `Gate::authorize()`, for the reason `SeatPickController` has none:
 * ownership is the whole authorisation, and `FindSeatClaimForAdmission`
 * answers null — a 404 — for anybody else. `store()` takes the picker's
 * own `PickPlacesData` capped at one place; `destroy()` gives the seat up.
 */
final class AdmissionSeatController
{
    public function show(
        Request $request,
        string $admission,
        FindSeatClaimForAdmission $finder,
        ComputePlanAvailability $availability,
    ): Response {
        $found = $finder->handle($admission, (string) $request->user()->getAuthIdentifier());
        abort_if($found === null, 404);
        ['claim' => $claim, 'admission' => $row] = $found;

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
            ->where('admission_id', $row->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->pluck('resource_id')
            ->all());

        $data = new SeatPickerData(
            registrationId: $claim->registration_id,
            claim: SeatClaimData::fromModel($claim),
            categories: $categories,
            plans: $plans,
            availability: $availability->handle($claim),
            currentPlaceIds: $currentPlaceIds,
            admissionId: $row->id,
            maxPlaces: 1,
        );

        return Inertia::render('seating/Pick', $data->toArray());
    }

    public function store(
        Request $request,
        string $admission,
        FindSeatClaimForAdmission $finder,
        AssignPlaceToAdmission $assign,
    ): RedirectResponse {
        $userId = (string) $request->user()->getAuthIdentifier();
        $found = $finder->handle($admission, $userId);
        abort_if($found === null, 404);

        $data = PickPlacesData::validateAndCreate($request);

        $assign->handle($found['claim'], $found['admission'], $data->placeIds[0], AssignPlaceToAdmission::ACTOR_REGISTRANT, $userId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your seat is saved.')]);

        return to_route('admission.tickets.show', ['admission' => $admission]);
    }

    public function destroy(
        Request $request,
        string $admission,
        FindSeatClaimForAdmission $finder,
        AssignPlaceToAdmission $assign,
    ): RedirectResponse {
        $userId = (string) $request->user()->getAuthIdentifier();
        $found = $finder->handle($admission, $userId);
        abort_if($found === null, 404);

        $assign->handle($found['claim'], $found['admission'], null, AssignPlaceToAdmission::ACTOR_REGISTRANT, $userId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The seat was given up.')]);

        return to_route('admission.tickets.show', ['admission' => $admission]);
    }

    public function availability(
        Request $request,
        string $admission,
        FindSeatClaimForAdmission $finder,
        ComputePlanAvailability $availability,
    ): JsonResponse {
        $found = $finder->handle($admission, (string) $request->user()->getAuthIdentifier());
        abort_if($found === null, 404);

        return response()->json($availability->handle($found['claim']));
    }
}
