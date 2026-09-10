<?php

declare(strict_types=1);

namespace Konventix\Resources\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Resources\Actions\CreatePlan;
use Konventix\Resources\Actions\ExportPlan;
use Konventix\Resources\Actions\ImportPlan;
use Konventix\Resources\Actions\PublishPlan;
use Konventix\Resources\Actions\ReleaseOperatorReservation;
use Konventix\Resources\Actions\ReservePlaceForOperator;
use Konventix\Resources\Actions\SavePlan;
use Konventix\Resources\Actions\UnpublishPlan;
use Konventix\Resources\Actions\ValidatePlan;
use Konventix\Resources\Data\CategoryData;
use Konventix\Resources\Data\PlanConflictData;
use Konventix\Resources\Data\PlanData;
use Konventix\Resources\Data\PlanEditorData;
use Konventix\Resources\Data\PlanOccupancyData;
use Konventix\Resources\Data\PlanOccupancyPlaceData;
use Konventix\Resources\Data\PlanRowData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Data\PlanViewData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\Permission;
use Konventix\Resources\Enums\PlaceState;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Exceptions\PlanConflictException;
use Konventix\Resources\Exceptions\PlanStaleException;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (the same trap fixed in AssignPlacesToRegistration.php).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;
use Konventix\Resources\Plans\PlanGeometry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator console: hall plans (design §8.2). This half is the index and
 * the endpoints around a plan — create, publish, unpublish, export, import,
 * occupancy, operator reservations, and the editor (`edit`, `update`,
 * `validate`, design §8.3).
 *
 * Runs on the admin host with no brand in context (ADR-0029): every query
 * escapes `BrandScope` explicitly and every created row gets its
 * `brand_id` from the request or from the plan row (design §8, §10).
 * Booking writes go only through the booking Actions (§10); this class
 * never touches `resource_bookings` directly.
 */
class PlanAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManagePlans->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'version', 'published_at', 'created_at'], default: 'name');
        $filters = $this->resolveFilters($request, ['event' => null]);

        $plans = Plan::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->when($filters['event'] !== null, fn ($query) => $query->where('event_id', $filters['event']))
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $eventNames = ResourceEventWindow::withoutBrandScope()
            ->whereIn('event_id', $plans->getCollection()->pluck('event_id')->unique()->all())
            ->pluck('name', 'event_id');

        // Grouped count, not withCount(): the latter re-applies BrandScope
        // on the counted model and reads zero on this host. Soft-deleted
        // places are excluded by the model's own scope.
        $placeCounts = Resource::withoutBrandScope()
            ->selectRaw('plan_id, count(*) as aggregate')
            ->whereIn('plan_id', $plans->getCollection()->pluck('id'))
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        return Inertia::render('admin/plans/Index', [
            'plans' => $plans->through(fn (Plan $plan): array => (new PlanRowData(
                id: (string) $plan->getKey(),
                name: $plan->name,
                eventName: $eventNames[$plan->event_id] ?? $plan->event_id,
                floorLabel: $plan->floor_label,
                places: (int) ($placeCounts[$plan->getKey()] ?? 0),
                version: $plan->version,
                publishedAt: $plan->published_at?->toIso8601String(),
            ))->toArray()),
            'brands' => $this->brandOptions(),
            'events' => $this->eventOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function store(Request $request, CreatePlan $createPlan): RedirectResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];

        $createPlan->handle(
            $brandId,
            PlanData::validateAndCreate($request->all()),
            $request->user()?->getKey(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan created.')]);

        return to_route('admin.plans.index');
    }

    /**
     * The editor (design §8.3). Categories and occupancy travel with the
     * page so the inspector has something to show before the first save;
     * the page refreshes occupancy through the `occupancy` action after a
     * reservation.
     */
    public function edit(string $plan): Response
    {
        Gate::authorize(Permission::ManagePlans->value);

        $plan = $this->find($plan);

        return Inertia::render('admin/plans/Edit', [
            'editor' => new PlanEditorData(
                plan: new PlanViewData(
                    id: $plan->getKey(),
                    name: $plan->name,
                    floorLabel: $plan->floor_label,
                    widthMm: $plan->width_mm,
                    heightMm: $plan->height_mm,
                    background: $plan->background,
                    document: $plan->document,
                ),
                eventId: $plan->event_id,
                version: $plan->version,
                snapStep: $plan->snap_step,
                unitDisplay: $plan->unit_display,
                publishedAt: $plan->published_at?->toIso8601String(),
                canReserve: Gate::allows(Permission::BookOnBehalf->value),
                categories: $this->editorCategories($plan),
                occupancy: $this->occupancyFor($plan),
            ),
        ]);
    }

    /**
     * The editor's save (design §6.1): 409 `{ currentVersion }` when the
     * version moved, 422 `{ conflicts }` when an occupied place would be
     * deleted, recategorised or blocked without a resolution, 200 with the
     * result otherwise. Data validation answers the framework's 422.
     *
     * The version pre-check keeps §6.1's order on the HTTP surface (stale
     * before invalid); `SavePlan` re-checks under its row lock, which is
     * the check that counts. The conflict list is mirrored under
     * `errors.conflicts` because Inertia's `useHttp` hands a 422's `errors`
     * key to `onError` and nothing else of the body.
     */
    public function update(Request $request, string $plan, SavePlan $savePlan): JsonResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $plan = $this->find($plan);

        if ($request->integer('expectedVersion', -1) !== $plan->version) {
            return response()->json(['currentVersion' => $plan->version], 409);
        }

        $data = PlanSaveData::validateAndCreate($request->all());

        try {
            $result = $savePlan->handle($plan, $data, $request->user()?->getKey(), PlanVersionSource::Editor);
        } catch (PlanStaleException $exception) {
            return response()->json(['currentVersion' => $exception->currentVersion], 409);
        } catch (PlanConflictException $exception) {
            $conflicts = array_map(
                fn (PlanConflictData $conflict): array => $conflict->toArray(),
                $exception->conflicts,
            );

            return response()->json(['conflicts' => $conflicts, 'errors' => ['conflicts' => $conflicts]], 422);
        }

        return response()->json($result);
    }

    /**
     * The dry run behind the editor's "Check" button: the same pipeline
     * without step 6, answering the conflict and warning lists as data.
     */
    public function validate(Request $request, string $plan, ValidatePlan $validatePlan): JsonResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $plan = $this->find($plan);

        if ($request->integer('expectedVersion', -1) !== $plan->version) {
            return response()->json(['currentVersion' => $plan->version], 409);
        }

        $data = PlanSaveData::validateAndCreate($request->all());

        try {
            return response()->json($validatePlan->handle($plan, $data));
        } catch (PlanStaleException $exception) {
            return response()->json(['currentVersion' => $exception->currentVersion], 409);
        }
    }

    public function publish(string $plan, PublishPlan $publishPlan): RedirectResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $publishPlan->handle($this->find($plan));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan published — its places are now bookable.')]);

        return back();
    }

    public function unpublish(string $plan, UnpublishPlan $unpublishPlan): RedirectResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $unpublishPlan->handle($this->find($plan));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan unpublished — its places are hidden from the picker.')]);

        return back();
    }

    /**
     * The canonical document as a file (design §6.4): the same bytes
     * `resources:plan-export` writes, so a round trip through the console
     * and the command is byte-stable.
     */
    public function export(string $plan, ExportPlan $exportPlan): StreamedResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $model = $this->find($plan);
        $json = $exportPlan->handle($model);

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            Str::slug($model->name).'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * A full document uploaded on the index becomes a new plan (design
     * §6.4). The file is read here and decoded here; what is inside it is
     * `ImportPlan`'s to validate through `SavePlan`, whose validation
     * errors reach the dialog like any other 422.
     */
    public function import(Request $request, CreatePlan $createPlan, ImportPlan $importPlan): RedirectResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        $validated = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'eventId' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            // 2 MB is generous for a document of 2,000 elements (§14 caps
            // v1 there); `mimes` is not used because a JSON body is guessed
            // as text by the sniffer and would be refused for the wrong reason.
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $document = json_decode((string) $validated['file']->get(), true);

        if (! is_array($document)) {
            throw ValidationException::withMessages([
                'file' => __('The file is not a JSON document.'),
            ]);
        }

        // `ImportPlan` (S2) replaces the document of an existing plan and
        // takes the model, not a brand id: the row is created here first,
        // with a placeholder canvas that the import's own projection then
        // grows past — `width_mm`/`height_mm` are re-derived from the
        // document bounds below, exactly as `PlanImportCommand` does.
        $plan = $createPlan->handle(
            $validated['brand_id'],
            PlanData::validateAndCreate([
                'eventId' => $validated['eventId'],
                'name' => $validated['name'],
                'floorLabel' => null,
                'widthMm' => 1000,
                'heightMm' => 1000,
            ]),
            $request->user()?->getKey(),
        );

        $importPlan->handle($plan, $document, $request->user()?->getKey());

        $bounds = (new PlanGeometry($plan->refresh()->document))->bounds();

        if ($bounds !== null) {
            $plan->forceFill([
                'width_mm' => max(1000, (int) (ceil($bounds->maxX / 1000) * 1000) + 1000),
                'height_mm' => max(1000, (int) (ceil($bounds->maxY / 1000) * 1000) + 1000),
            ])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan imported.')]);

        return to_route('admin.plans.index');
    }

    /**
     * Who holds what, per place (design §8.2 `PlanOccupancyData`), for the
     * editor overlay. Availability is a query over the bookings, never a
     * column on the place (§6.2); the vocabulary matches the picker's
     * `PlaceState` minus `mine`, which has no meaning for an operator.
     */
    public function occupancy(string $plan): JsonResponse
    {
        Gate::authorize(Permission::ManagePlans->value);

        return response()->json($this->occupancyFor($this->find($plan)));
    }

    /**
     * An orga reservation on one place (design §7.3): hold plus confirm with
     * holder `operator`. A taken place fails through the exclusion
     * constraint inside the Action and comes back as a field error.
     */
    public function reserve(Request $request, string $plan, string $place, ReservePlaceForOperator $reservePlace): RedirectResponse
    {
        Gate::authorize(Permission::BookOnBehalf->value);

        $model = $this->find($plan);
        $resource = $this->findPlace($model, $place);

        try {
            $reservePlace->handle($resource, (string) $request->user()->getKey());
        } catch (ResourceUnavailableException) {
            throw ValidationException::withMessages([
                'place' => __('Place :ref is already taken.', ['ref' => $resource->ref ?? $resource->label]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Place :ref reserved.', ['ref' => $resource->ref ?? $resource->label])]);

        return back();
    }

    public function unreserve(string $plan, string $place, ReleaseOperatorReservation $releaseReservation): RedirectResponse
    {
        Gate::authorize(Permission::BookOnBehalf->value);

        $model = $this->find($plan);
        $resource = $this->findPlace($model, $place);

        /** @var ResourceBooking|null $booking */
        $booking = ResourceBooking::withoutBrandScope()
            ->where('resource_id', $resource->getKey())
            ->where('holder_type', ResourceBooking::HOLDER_OPERATOR)
            ->whereIn('state', BookingState::occupyingValues())
            ->first();

        if ($booking === null) {
            throw ValidationException::withMessages([
                'place' => __('Place :ref carries no operator reservation.', ['ref' => $resource->ref ?? $resource->label]),
            ]);
        }

        $releaseReservation->handle($booking);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reservation released.')]);

        return back();
    }

    private function find(string $plan): Plan
    {
        /** @var Plan */
        return Plan::withoutBrandScope()->findOrFail($plan);
    }

    /**
     * A place of *this* plan — the plan id in the URL is not decoration: a
     * place id from another plan or brand 404s rather than being acted on.
     */
    private function findPlace(Plan $plan, string $place): Resource
    {
        /** @var BookableResource */
        return Resource::withoutBrandScope()
            ->where('plan_id', $plan->getKey())
            ->findOrFail($place);
    }

    /**
     * @return list<array{id: string, name: string, brandId: string}>
     */
    private function eventOptions(): array
    {
        return array_values(ResourceEventWindow::withoutBrandScope()
            ->orderBy('name')
            ->get()
            ->map(fn (ResourceEventWindow $window): array => [
                'id' => $window->event_id,
                'name' => (string) ($window->name ?? $window->event_id),
                'brandId' => $window->brand_id,
            ])
            ->all());
    }

    /**
     * The categories a place of this plan may carry: the plan's event and
     * brand only, in the operator's sort order.
     *
     * @return list<CategoryData>
     */
    private function editorCategories(Plan $plan): array
    {
        return array_values(ResourceType::withoutBrandScope()
            ->where('brand_id', $plan->brand_id)
            ->where('event_id', $plan->event_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ResourceType $type): CategoryData => new CategoryData(
                id: $type->getKey(),
                name: $type->name,
                token: $type->token,
                symbol: $type->symbol,
                description: $type->description,
            ))
            ->all());
    }

    /**
     * Who holds what, per place (design §8.2 `PlanOccupancyData`): shared
     * by the `occupancy` action and the editor's initial props, so the two
     * never compute it differently.
     */
    private function occupancyFor(Plan $plan): PlanOccupancyData
    {
        $places = Resource::withoutBrandScope()
            ->where('plan_id', $plan->getKey())
            ->get(['id', 'blocked']);

        $occupying = ResourceBooking::withoutBrandScope()
            ->whereIn('resource_id', $places->pluck('id'))
            ->whereIn('state', BookingState::occupyingValues())
            ->get()
            ->keyBy('resource_id');

        /** @var array<string, PlanOccupancyPlaceData> $result */
        $result = [];

        foreach ($places as $place) {
            /** @var ResourceBooking|null $booking */
            $booking = $occupying->get($place->getKey());

            $result[(string) $place->getKey()] = new PlanOccupancyPlaceData(
                state: match (true) {
                    $place->blocked => PlaceState::Unavailable,
                    $booking === null => PlaceState::Free,
                    $booking->state === BookingState::Held => PlaceState::Held,
                    default => PlaceState::Booked,
                },
                holderType: $booking?->holder_type,
                registrationId: $booking?->registration_id,
                bookingId: $booking === null ? null : (string) $booking->getKey(),
            );
        }

        return new PlanOccupancyData(places: $result);
    }
}
