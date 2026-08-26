<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\CreateStaticPage;
use Konventix\Platform\Actions\DeleteStaticPage;
use Konventix\Platform\Actions\SaveStaticPage;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Data\Blocks\BlockTypeOptionData;
use Konventix\Platform\Data\CreateStaticPageData;
use Konventix\Platform\Data\MediaItemData;
use Konventix\Platform\Data\StaticPageData;
use Konventix\Platform\Data\StaticPageRowData;
use Konventix\Platform\Data\UpdateStaticPageData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: a brand's static pages, composed of registered block
 * types (ROADMAP slice 21a, ADR-0029). Route middleware already requires the
 * admin host, auth and platform.access-admin; per-operation capabilities are
 * checked here.
 */
class StaticPageAdminController
{
    use HasDataTableQuery;

    /** How many images the picker offers, mirroring BrandAdminController. */
    private const PICKABLE_MEDIA = 200;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewStaticPages->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['title', 'slug', 'published_at', 'updated_at'], default: 'title');
        $filters = ['brand' => $this->resolveSelectionFilter($request, 'brand', $selection->brandId())];

        $brandNames = Brand::query()->pluck('name', 'id');

        $pages = StaticPage::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($inner) => $inner->where('title', 'ilike', $term)->orWhere('slug', 'ilike', $term));
            })
            ->when($filters['brand'] !== null, fn ($query) => $query->forBrand($filters['brand']))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/pages/Index', [
            'pages' => $pages->through(fn (StaticPage $page): array => (new StaticPageRowData(
                id: $page->getKey(),
                title: $page->title,
                slug: $page->slug,
                brandName: $brandNames[$page->brand_id] ?? '—',
                published: $page->published_at !== null,
                updatedAt: $page->updated_at?->toDateTimeString(),
            ))->toArray()),
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function store(CreateStaticPageData $data, CreateStaticPage $create, ConsoleSelection $selection): RedirectResponse
    {
        Gate::authorize(Permission::ManageStaticPages->value);

        $brandId = $data->brandId ?? $selection->brandId();
        $brand = $brandId === null ? null : Brand::query()->find($brandId);

        if ($brand === null) {
            throw ValidationException::withMessages(['brandId' => __('Pick a brand first.')]);
        }

        $page = $create->handle($brand, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page created — now add its blocks.')]);

        return to_route('admin.pages.edit', $page->getKey());
    }

    public function edit(string $page, BlockRegistry $registry, MediaLibrary $library, ConsoleEventSources $events, BrandContext $brandContext): Response
    {
        Gate::authorize(Permission::ViewStaticPages->value);

        $model = StaticPage::withoutBrandScope()->findOrFail($page);
        $brand = Brand::query()->findOrFail($model->brand_id);

        return Inertia::render('admin/pages/Edit', [
            'page' => StaticPageData::ofModel($model, $brand->name, $model->published_at === null ? null : $this->publicUrl($brand, $model))->toArray(),
            // Editable types are resolved with the page's brand in context:
            // the gate answers per brand, and the admin host has none.
            'types' => $brandContext->withBrand($brand, fn (): array => array_values(array_map(
                fn ($type): array => BlockTypeOptionData::ofType($type)->toArray(),
                $registry->editable(),
            ))),
            'context' => [
                'media' => MediaItem::withoutBrandScope()
                    ->withCount(['usages' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
                    ->where('brand_id', $brand->getKey())
                    ->where('mime_type', 'like', 'image/%')
                    ->latest()
                    ->limit(self::PICKABLE_MEDIA)
                    ->get()
                    ->map(fn (MediaItem $item): MediaItemData => MediaItemData::fromModel($item, $library))
                    ->all(),
                'events' => $events->hasAny() ? $events->eventsFor($brand->getKey()) : [],
            ],
        ]);
    }

    public function update(string $page, UpdateStaticPageData $data, SaveStaticPage $save): RedirectResponse
    {
        Gate::authorize(Permission::ManageStaticPages->value);

        $model = StaticPage::withoutBrandScope()->findOrFail($page);
        $save->handle($model, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page saved.')]);

        return to_route('admin.pages.edit', $model->getKey());
    }

    public function destroy(string $page, DeleteStaticPage $delete): RedirectResponse
    {
        Gate::authorize(Permission::ManageStaticPages->value);

        $delete->handle(StaticPage::withoutBrandScope()->findOrFail($page));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page deleted.')]);

        return to_route('admin.pages.index');
    }

    private function publicUrl(Brand $brand, StaticPage $page): ?string
    {
        $host = $brand->domains()->orderByDesc('is_primary')->value('host');

        return $host === null ? null : 'http://'.$host.($page->slug === 'home' ? '/' : '/pages/'.$page->slug);
    }
}
