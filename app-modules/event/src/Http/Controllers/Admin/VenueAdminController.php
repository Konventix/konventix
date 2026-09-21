<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Actions\CreateVenue;
use Konventix\Event\Actions\UpdateVenue;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: brand-owned, reusable venues (ADR-0029). Same
 * withoutBrandScope reasoning as EventAdminController.
 *
 * The index keeps the create dialog — a venue starts as a name and an
 * address — and everything else about a venue (description, both pairs of
 * coordinates, arrival, parking, loading zones, seat plan, pictures) lives
 * on its own edit page, which is too much for a dialog.
 */
class VenueAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewVenues->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'city', 'created_at'], default: 'name');

        $venues = Venue::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)->orWhere('city', 'ilike', $term);
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/venues/Index', [
            'venues' => $venues->through(fn (Venue $venue): array => [
                'id' => $venue->getKey(),
                ...VenueData::from($venue)->toArray(),
            ]),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $brand): array => ['id' => $brand->getKey(), 'name' => $brand->name])
                ->all(),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    public function store(Request $request, CreateVenue $createVenue): RedirectResponse
    {
        Gate::authorize(Permission::ManageVenues->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];

        $venue = $createVenue->handle($brandId, VenueData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue created.')]);

        // Straight into the edit page: the dialog asked for a name and an
        // address, and everything a public venue page renders is on the
        // other side of this redirect.
        return to_route('admin.venues.edit', $venue->getKey());
    }

    public function edit(string $venue, MediaLibrary $library): Response
    {
        Gate::authorize(Permission::ManageVenues->value);

        $model = $this->find($venue);

        return Inertia::render('admin/venues/Edit', [
            'venue' => [
                'id' => $model->getKey(),
                'brandId' => $model->brand_id,
                ...VenueData::from($model)->toArray(),
            ],
            // The picture picker's candidates, through Platform's own
            // doorway rather than a MediaItem query of this module's
            // (ADR-0035).
            'media' => $library->pickableImages($model->brand_id),
        ]);
    }

    public function update(Request $request, string $venue, UpdateVenue $updateVenue): RedirectResponse
    {
        Gate::authorize(Permission::ManageVenues->value);

        $model = $this->find($venue);

        $updateVenue->handle($model, VenueData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue updated.')]);

        return to_route('admin.venues.edit', $model->getKey());
    }

    private function find(string $venue): Venue
    {
        /** @var Venue */
        return Venue::withoutBrandScope()->findOrFail($venue);
    }
}
