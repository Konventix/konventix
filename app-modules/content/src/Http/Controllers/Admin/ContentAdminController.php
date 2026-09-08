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
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;

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

    public function index(Request $request, BrandUrl $brandUrl, ConsoleSelection $selection): Response
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

        $filters = [
            ...$this->resolveFilters($request, [
                'type' => $this->typeValues(),
                'state' => $this->stateValues(),
            ]),
            // The console's pick is this list's default, cleared per list
            // with `brand=all` (ADR-0029). An event filter is owed too, but
            // Content has no event read model yet — see TODO.md.
            'brand' => $this->resolveSelectionFilter($request, 'brand', $selection->brandId()),
        ];

        $entries = ContentEntry::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('title', 'ilike', $term)->orWhere('slug', 'ilike', $term);
                });
            })
            ->when($filters['brand'] !== null, fn ($query) => $query->forBrand($filters['brand']))
            ->when($filters['type'] !== null, fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['state'] !== null, fn ($query) => $query->where('state', $filters['state']))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);
        /** @var array<string, string> $brandNames */
        $brandNames = $brands->pluck('name', 'id')->all();

        // One lookup for the page, not one per row.
        $brandBases = $brandUrl->basesFor($brands);

        // Content's own author read model (ADR-0004), and not brand-owned,
        // so it needs no escape from `BrandScope`. Entries may be credited
        // to nobody, and a null has no name to look up.
        /** @var array<string, string> $authorNames */
        $authorNames = Author::query()
            ->whereIn('id', $entries->getCollection()->pluck('author_user_id')->filter()->unique())
            ->pluck('display_name', 'id')
            ->all();

        return Inertia::render('admin/content/Index', [
            'entries' => $entries->through(fn (ContentEntry $entry): array => [
                'id' => $entry->getKey(),
                'brandName' => $brandNames[$entry->brand_id] ?? '—',
                'title' => $entry->title,
                'slug' => $entry->slug,
                'authorName' => $entry->author_user_id === null
                    ? null
                    : ($authorNames[$entry->author_user_id] ?? __('Participant')),
                'type' => $entry->type->value,
                'state' => $entry->state->value,
                // ISO with an offset, not naked UTC digits: the console
                // renders these through `formatDateTime()` in the reader's own
                // zone, and a zone-less string is parsed as their wall clock —
                // the console would confirm the very value that is wrong
                // (#208, #246).
                'publishedAt' => $entry->published_at?->toIso8601String(),
                'updatedAt' => $entry->updated_at?->toIso8601String(),
                'publicUrl' => $this->publicUrl($entry, $brandBases[$entry->brand_id] ?? null),
            ]),
            'brands' => $this->brandOptionsFrom($brands),
            'types' => $this->typeValues(),
            'states' => $this->stateValues(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
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
                'publishedAt' => $model->published_at?->toIso8601String(),
                'brandName' => $brand === null ? '—' : $brand->name,
                'publicUrl' => $this->publicUrl(
                    $model,
                    $brand === null ? null : $brandUrl->baseFor($brand),
                ),
                ...ContentEntryData::fromModel($model)->toArray(),
            ],
            'types' => $this->typeValues(),
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
     * @return list<string>
     */
    private function typeValues(): array
    {
        return array_map(fn (ContentType $case): string => $case->value, ContentType::cases());
    }

    /**
     * @return list<string>
     */
    private function stateValues(): array
    {
        return array_map(fn (PublicationState $case): string => $case->value, PublicationState::cases());
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
     * The trait's `brandOptions()` would re-query; this page already holds
     * the brand models for the public-URL bases, so it maps those.
     *
     * @param  Collection<int, Brand>  $brands
     * @return list<array{id: string, name: string}>
     */
    private function brandOptionsFrom(Collection $brands): array
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
