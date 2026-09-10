<?php

declare(strict_types=1);

namespace Konventix\Resources\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\CreateResourceType;
use Konventix\Resources\Actions\UpdateResourceType;
use Konventix\Resources\Data\ResourceTypeData;
use Konventix\Resources\Data\ResourceTypeRowData;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Enums\Permission;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Operator console: categories (design §8.1). A category is a
 * `ResourceType` (§4.1) — the console says "category" because that is what
 * a place, a grant and the picker call it.
 *
 * Runs on the admin host, where `BrandScope` hardens every brand-owned
 * query to zero rows (ADR-0029): every query here escapes it deliberately,
 * relation counts included (.ai/rules/app-modules-src.md), and the event
 * picker reads this module's own window projection, never Event's tables
 * (ADR-0004).
 *
 * Every place count this controller shows the operator is the **bookable**
 * one (`Resource::scopeBookable()`: published, not blocked; deleted rows
 * are already excluded by the default query) — never a raw row count. A
 * seat category's ceiling becomes a hand-typed Registration quota capacity
 * (design §12b, #282), and a raw count would tell the operator to oversell
 * by however many places are still draft, blocked or unpublished.
 */
class ResourceTypeAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageResourceTypes->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'sort_order', 'created_at'], default: 'name');
        $filters = $this->resolveFilters($request, ['event' => null]);

        $types = ResourceType::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->when($filters['event'] !== null, fn ($query) => $query->where('event_id', $filters['event']))
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $eventNames = ResourceEventWindow::withoutBrandScope()
            ->whereIn('event_id', $types->getCollection()->pluck('event_id')->unique()->all())
            ->pluck('name', 'event_id');

        // Bookable, not raw: this is the number an operator reads off the
        // list to size a Registration quota. A grouped count rather than
        // withCount(): the latter re-applies BrandScope on the counted
        // model and reads zero on this host.
        $placeCounts = Resource::withoutBrandScope()
            ->bookable()
            ->selectRaw('resource_type_id, count(*) as aggregate')
            ->whereIn('resource_type_id', $types->getCollection()->pluck('id'))
            ->groupBy('resource_type_id')
            ->pluck('aggregate', 'resource_type_id');

        return Inertia::render('admin/resource-types/Index', [
            'types' => $types->through(fn (ResourceType $type): array => (new ResourceTypeRowData(
                id: (string) $type->getKey(),
                name: $type->name,
                slug: $type->slug,
                eventName: $eventNames[$type->event_id] ?? $type->event_id,
                mode: $type->mode,
                token: $type->token,
                symbol: $type->symbol,
                sortOrder: $type->sort_order,
                places: (int) ($placeCounts[$type->getKey()] ?? 0),
            ))->toArray()),
            'brands' => $this->brandOptions(),
            'events' => $this->eventOptions(),
            'options' => $this->axisOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function store(Request $request, CreateResourceType $createResourceType): RedirectResponse
    {
        Gate::authorize(Permission::ManageResourceTypes->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $type = $createResourceType->handle($brandId, ResourceTypeData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('admin.resource-types.edit', $type->getKey());
    }

    public function edit(string $resourceType): Response
    {
        Gate::authorize(Permission::ManageResourceTypes->value);

        $type = $this->find($resourceType);

        /** @var Brand|null $brand */
        $brand = Brand::query()->whereKey($type->brand_id)->first();

        return Inertia::render('admin/resource-types/Edit', [
            'type' => [
                'id' => (string) $type->getKey(),
                'brandName' => $brand->name ?? '—',
                ...ResourceTypeData::fromModel($type)->toArray(),
            ],
            'events' => $this->eventOptions($type->brand_id),
            'options' => $this->axisOptions(),
            // Bookable, not raw — see the class docblock. `Edit.vue` also
            // uses this number to decide whether identity axes are frozen;
            // `UpdateResourceType::guardFrozenAxes()` is the authority on
            // that and guards on *any* place row, published or not, so a
            // draft-only category can still be refused server-side even
            // though this hint reads zero.
            'places' => Resource::withoutBrandScope()->where('resource_type_id', $type->getKey())->bookable()->count(),
        ]);
    }

    public function update(Request $request, string $resourceType, UpdateResourceType $updateResourceType): RedirectResponse
    {
        Gate::authorize(Permission::ManageResourceTypes->value);

        $type = $this->find($resourceType);

        $updateResourceType->handle($type, ResourceTypeData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category saved.')]);

        return to_route('admin.resource-types.edit', $type->getKey());
    }

    private function find(string $resourceType): ResourceType
    {
        /** @var ResourceType */
        return ResourceType::withoutBrandScope()->findOrFail($resourceType);
    }

    /**
     * The events this module knows, by name (design §4.7). Optionally
     * narrowed to one brand for the editor, where the type's brand is fixed.
     *
     * @return list<array{id: string, name: string, brandId: string}>
     */
    private function eventOptions(?string $brandId = null): array
    {
        return array_values(ResourceEventWindow::withoutBrandScope()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
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
     * Every axis value the form may offer, from the enums themselves.
     *
     * @return array<string, list<string>>
     */
    private function axisOptions(): array
    {
        return [
            'mode' => array_column(CapacityMode::cases(), 'value'),
            'token' => array_column(CategoryToken::cases(), 'value'),
        ];
    }
}
