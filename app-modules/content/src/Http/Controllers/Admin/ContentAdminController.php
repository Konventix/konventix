<?php

declare(strict_types=1);

namespace Konventix\Content\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Content\Actions\CreateEntry;
use Konventix\Content\Actions\UpdateEntry;
use Konventix\Content\Data\ContentEntryData;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Permission;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: editorial entries (ADR-0029). Route middleware already
 * requires the admin host, auth and `platform.access-admin`; the per-operation
 * capabilities are checked here — authoring (`ManageEntries`) is separate from
 * publishing (`PublishEntries`), which is the whole point of Content's
 * capability split.
 *
 * Entries are brand-owned (ADR-0010) and the admin host hardens `BrandScope`
 * to zero rows (ADR-0029), so every query here escapes it deliberately —
 * including the comment count, which re-applies the scope on the related
 * model and would otherwise read 0 for every row (.ai/rules).
 */
class ContentAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, BrandUrl $brandUrl): Response
    {
        Gate::authorize(Permission::ViewEntries->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['title', 'slug', 'type', 'state', 'published_at', 'updated_at'],
            default: 'updated_at',
        );

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $type = $this->filterValue($request, 'type', ContentType::cases());
        $state = $this->filterValue($request, 'state', PublicationState::cases());

        $entries = ContentEntry::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('title', 'ilike', $term)->orWhere('slug', 'ilike', $term);
                });
            })
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($state !== null, fn ($query) => $query->where('state', $state))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);
        /** @var array<string, string> $brandNames */
        $brandNames = $brands->pluck('name', 'id')->all();

        // One lookup for the page, not one per row.
        $brandBases = $brandUrl->basesFor($brands);

        return Inertia::render('admin/content/Index', [
            'entries' => $entries->through(fn (ContentEntry $entry): array => [
                'id' => $entry->getKey(),
                'brandName' => $brandNames[$entry->brand_id] ?? '—',
                'title' => $entry->title,
                'slug' => $entry->slug,
                'type' => $entry->type->value,
                'state' => $entry->state->value,
                'publishedAt' => $entry->published_at?->toDateTimeString(),
                'updatedAt' => $entry->updated_at?->toDateTimeString(),
                'publicUrl' => $this->publicUrl($entry, $brandBases[$entry->brand_id] ?? null),
            ]),
            'brands' => $this->brandOptions($brands),
            'types' => array_map(fn (ContentType $case): string => $case->value, ContentType::cases()),
            'states' => array_map(fn (PublicationState $case): string => $case->value, PublicationState::cases()),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'type' => $type,
                'state' => $state,
            ],
            'pendingComments' => ContentComment::withoutBrandScope()
                ->where('state', CommentState::Pending)
                ->count(),
        ]);
    }

    public function store(Request $request, CreateEntry $createEntry): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntries->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $data = ContentEntryData::validateAndCreate($request->all());

        $entry = $createEntry->handle($brandId, $data, (string) $request->user()->getKey());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry created.')]);

        return to_route('admin.content.edit', $entry->getKey());
    }

    public function edit(string $entry, BrandUrl $brandUrl): Response
    {
        Gate::authorize(Permission::ManageEntries->value);

        $model = $this->find($entry);

        /** @var Brand|null $brand */
        $brand = Brand::query()->whereKey($model->brand_id)->first(['id', 'name']);

        return Inertia::render('admin/content/Edit', [
            'entry' => [
                'id' => $model->getKey(),
                'state' => $model->state->value,
                'publishedAt' => $model->published_at?->toDateTimeString(),
                'brandName' => $brand === null ? '—' : $brand->name,
                'publicUrl' => $this->publicUrl(
                    $model,
                    $brand === null ? null : $brandUrl->baseFor($brand),
                ),
                ...ContentEntryData::fromModel($model)->toArray(),
            ],
            'types' => array_map(fn (ContentType $case): string => $case->value, ContentType::cases()),
            'audiences' => array_map(fn (Audience $case): string => $case->value, Audience::cases()),
            'urgencies' => array_map(fn (Urgency $case): string => $case->value, Urgency::cases()),
            // Exactly the transitions the lifecycle Actions will accept, so
            // the console never offers a button that would be refused.
            'transitions' => $this->legalTransitions($model),
            'comments' => $this->commentSummary($model),
        ]);
    }

    public function update(Request $request, string $entry, UpdateEntry $updateEntry): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntries->value);

        $model = $this->find($entry);

        $updateEntry->handle($model, ContentEntryData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry saved.')]);

        return to_route('admin.content.edit', $model->getKey());
    }

    /**
     * Where a reader would find this entry, or null when there is nothing to
     * look at yet.
     *
     * Two ways to be null, and both are honest: a brand with no domain has
     * nowhere to serve it from, and an entry that is not live has no public
     * page at all. Offering a link that 404s would be worse than offering
     * none — the console has no preview surface for drafts (Content's
     * TODO.md), and pretending otherwise is how an editor learns to distrust
     * the button.
     *
     * The path comes from the route rather than from a string spelled out
     * here; the brand's origin is the only part the console has to supply.
     */
    private function publicUrl(ContentEntry $entry, ?string $brandBase): ?string
    {
        if ($brandBase === null || ! $entry->isLive()) {
            return null;
        }

        return $brandBase.route('content.show', ['slug' => $entry->slug], absolute: false);
    }

    private function find(string $entry): ContentEntry
    {
        /** @var ContentEntry */
        return ContentEntry::withoutBrandScope()->findOrFail($entry);
    }

    /**
     * @param  array<int, ContentType|PublicationState>  $cases
     */
    private function filterValue(Request $request, string $key, array $cases): ?string
    {
        $value = $request->string($key)->toString();
        $allowed = array_map(fn (ContentType|PublicationState $case): string => $case->value, $cases);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @return array{pending: int, total: int}
     */
    private function commentSummary(ContentEntry $entry): array
    {
        $comments = ContentComment::withoutBrandScope()->where('content_entry_id', $entry->getKey());

        return [
            'pending' => (clone $comments)->where('state', CommentState::Pending)->count(),
            'total' => $comments->count(),
        ];
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @return list<array{id: string, name: string}>
     */
    private function brandOptions(Collection $brands): array
    {
        return array_values($brands
            ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
            ->all());
    }

    /**
     * @return list<string>
     */
    private function legalTransitions(ContentEntry $entry): array
    {
        return match ($entry->state) {
            PublicationState::Draft => ['publish', 'archive'],
            PublicationState::Scheduled => ['publish', 'unpublish', 'archive'],
            PublicationState::Published => ['unpublish', 'archive'],
            PublicationState::Unpublished => ['publish', 'archive'],
            PublicationState::Archived => [],
        };
    }
}
