<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Actions\ArchiveEntryCondition;
use Konventix\Registration\Actions\CreateEntryCondition;
use Konventix\Registration\Actions\UpdateEntryCondition;
use Konventix\Registration\Data\EntryConditionData;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\RegistrationType;

/**
 * Operator console: what a reduced fare rests on (ADR-0066, spec §9.1).
 *
 * Behind `registration.manage-entry-conditions` (D-REG-24) — a product job
 * beside the registration types, and deliberately not part of
 * `registration.desk`: the desk judges a declaration, it does not write the
 * terms of the offer.
 *
 * Conditions are brand-owned and this is the admin host, where `BrandScope`
 * hardens to zero rows (ADR-0029) — so every query escapes it deliberately,
 * per relation included (.ai/rules).
 *
 * There is no destroy: a condition is archived, never deleted (spec §4.2).
 * The editor says out loud that `operator_checked` is advisory and that a
 * blocking check is `approval: required` on the type (D-REG-22) — two
 * mechanisms for "a human decides first" would be one too many.
 */
class EntryConditionAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageEntryConditions->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'key', 'verification', 'created_at'], default: 'name');

        $showArchived = $request->string('archived')->toString() === 'yes';

        $conditions = EntryCondition::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($inner) => $inner->where('name', 'ilike', $term)->orWhere('key', 'ilike', $term));
            })
            ->when($request->filled('brand'), fn ($query) => $query->where('brand_id', $request->string('brand')->toString()))
            ->unless($showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $brands = Brand::query()
            ->whereIn('id', $conditions->getCollection()->pluck('brand_id')->unique()->all())
            ->pluck('name', 'id');

        // withCount() re-applies BrandScope on the counted relation, which
        // reads zero on this host — count through the unscoped models
        // instead (.ai/rules).
        $typeCounts = RegistrationType::withoutBrandScope()
            ->join('registration_type_entry_condition as pivot', 'pivot.registration_type_id', '=', 'registration_types.id')
            ->whereIn('pivot.entry_condition_id', $conditions->getCollection()->pluck('id'))
            ->selectRaw('pivot.entry_condition_id as condition_id, count(*) as aggregate')
            ->groupBy('pivot.entry_condition_id')
            ->pluck('aggregate', 'condition_id');

        return Inertia::render('admin/entry-conditions/Index', [
            'conditions' => $conditions->through(fn (EntryCondition $condition): array => [
                'id' => (string) $condition->getKey(),
                'brandName' => $brands[$condition->brand_id] ?? $condition->brand_id,
                'key' => $condition->key,
                'name' => $condition->name,
                'assertionText' => $condition->assertion_text,
                'verification' => $condition->verification->value,
                'archived' => $condition->isArchived(),
                'types' => (int) ($typeCounts[(string) $condition->getKey()] ?? 0),
            ]),
            'brands' => $this->brandOptions(),
            'options' => $this->conditionOptions(),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'brand' => $request->string('brand')->toString() ?: null,
                'archived' => $showArchived ? 'yes' : null,
            ],
        ]);
    }

    public function store(Request $request, CreateEntryCondition $createEntryCondition): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntryConditions->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];

        $condition = $createEntryCondition->handle($brandId, EntryConditionData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry condition created.')]);

        return to_route('admin.entry-conditions.edit', $condition->getKey());
    }

    public function edit(string $entryCondition): Response
    {
        Gate::authorize(Permission::ManageEntryConditions->value);

        $condition = $this->find($entryCondition);

        /** @var Brand|null $brand */
        $brand = Brand::query()->whereKey($condition->brand_id)->first();

        $types = RegistrationType::withoutBrandScope()
            ->join('registration_type_entry_condition as pivot', 'pivot.registration_type_id', '=', 'registration_types.id')
            ->where('pivot.entry_condition_id', $condition->getKey())
            ->orderBy('registration_types.name')
            ->get(['registration_types.id', 'registration_types.name']);

        return Inertia::render('admin/entry-conditions/Edit', [
            'condition' => [
                'id' => (string) $condition->getKey(),
                'brandName' => $brand->name ?? '—',
                'assertions' => $condition->assertions()->count(),
                ...EntryConditionData::fromModel($condition)->toArray(),
            ],
            'events' => $this->eventOptions($condition->brand_id, include: $condition->event_id),
            'options' => $this->conditionOptions(),
            'types' => $types->map(fn (RegistrationType $type): array => [
                'id' => (string) $type->getKey(),
                'name' => $type->name,
            ])->all(),
        ]);
    }

    public function update(Request $request, string $entryCondition, UpdateEntryCondition $updateEntryCondition): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntryConditions->value);

        $condition = $this->find($entryCondition);

        $updateEntryCondition->handle($condition, EntryConditionData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry condition saved.')]);

        return to_route('admin.entry-conditions.edit', $condition->getKey());
    }

    /**
     * Archive, never delete (spec §4.2): the condition leaves the type
     * editor's picker and stays readable on every registration that ever
     * asserted it.
     */
    public function archive(string $entryCondition, ArchiveEntryCondition $archiveEntryCondition): RedirectResponse
    {
        Gate::authorize(Permission::ManageEntryConditions->value);

        $archiveEntryCondition->handle($this->find($entryCondition));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry condition archived. Past declarations keep it.')]);

        return to_route('admin.entry-conditions.index');
    }

    private function find(string $entryCondition): EntryCondition
    {
        /** @var EntryCondition */
        return EntryCondition::withoutBrandScope()->findOrFail($entryCondition);
    }

    /**
     * Every choice the editor may offer, from the enum itself — a fourth
     * verification kind would show up in the console without anybody editing
     * a page, and there is deliberately no fourth (D-REG-22).
     *
     * @return array<string, list<string>>
     */
    private function conditionOptions(): array
    {
        return [
            'verification' => array_column(EntryConditionVerification::cases(), 'value'),
        ];
    }

    /**
     * The brand's events, from this module's own read model (ADR-0004). An
     * archived event already named by the condition stays on offer, or
     * editing the wording would silently move it.
     *
     * @return list<array{id: string, name: string}>
     */
    private function eventOptions(string $brandId, ?string $include = null): array
    {
        return array_values(EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('event_id', $include))
            ->orderBy('name')
            ->get(['event_id', 'name'])
            ->map(fn (EventSummary $summary): array => [
                'id' => $summary->event_id,
                'name' => $summary->name,
            ])
            ->all());
    }
}
