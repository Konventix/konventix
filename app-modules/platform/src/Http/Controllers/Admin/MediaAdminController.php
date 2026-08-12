<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\DeleteMediaItem;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Data\MediaItemData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Scopes\BrandScope;

/**
 * Operator console: the brand-scoped media library (ADR-0035, ADR-0029).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; per-operation capabilities are checked here.
 * Media is brand-owned and the admin host hardens `BrandScope` to nothing,
 * so the index spans brands through the explicit `withoutBrandScope()`
 * escape hatch deliberately.
 */
class MediaAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, MediaLibrary $library): Response
    {
        Gate::authorize(Permission::ViewMedia->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['original_filename', 'size_bytes', 'created_at'],
            default: 'created_at',
        );

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $items = MediaItem::withoutBrandScope()
            // The count subquery hits brand-scoped MediaUsage — without the
            // explicit escape it reads 0 on the brand-less admin host.
            ->withCount(['usages' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where('original_filename', 'ilike', $term);
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/media/Index', [
            'media' => $items->through(fn (MediaItem $item): MediaItemData => MediaItemData::fromModel($item, $library)),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $brand): array => ['id' => $brand->getKey(), 'name' => $brand->name])
                ->all(),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    public function store(Request $request, StoreMediaUpload $storeMediaUpload): RedirectResponse
    {
        Gate::authorize(Permission::ManageMedia->value);

        $validated = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,svg,pdf'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $storeMediaUpload->handle(
            brandId: $validated['brand_id'],
            file: $validated['file'],
            altText: $validated['alt_text'] ?? null,
            uploadedByUserId: $request->user()?->getKey(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File uploaded.')]);

        return to_route('admin.media.index');
    }

    public function destroy(string $media, DeleteMediaItem $deleteMediaItem): RedirectResponse
    {
        Gate::authorize(Permission::ManageMedia->value);

        /** @var MediaItem $item */
        $item = MediaItem::withoutBrandScope()->findOrFail($media);

        $deleteMediaItem->handle($item);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File deleted.')]);

        return to_route('admin.media.index');
    }
}
