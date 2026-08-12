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
use Konventix\Platform\Models\Brand;

/**
 * Operator console: brand-owned, reusable venues (ADR-0029). Same
 * withoutBrandScope reasoning as EventAdminController.
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

        $createVenue->handle($brandId, VenueData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue created.')]);

        return to_route('admin.venues.index');
    }

    public function update(Request $request, string $venue, UpdateVenue $updateVenue): RedirectResponse
    {
        Gate::authorize(Permission::ManageVenues->value);

        /** @var Venue $model */
        $model = Venue::withoutBrandScope()->findOrFail($venue);

        $updateVenue->handle($model, VenueData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Venue updated.')]);

        return to_route('admin.venues.index');
    }
}
