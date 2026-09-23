<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\CreateDiscipline;
use Konventix\Competition\Actions\UpdateDiscipline;
use Konventix\Competition\Data\DisciplineData;
use Konventix\Competition\Data\DisciplineRowData;
use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Enums\LinkedProvider;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: disciplines (design §9). What is being contested —
 * a video game, a pinball table, a quiz, a judged contest (ADR-0024) — and
 * the contest defaults every competition of that discipline inherits: team
 * size, the duration hint the later schedule estimator reads, and whether
 * draws exist (ADR-0062: the discipline is the source of record, not the
 * GameOps game), and the linked account every player must have (design §5.6).
 *
 * Runs on the admin host, where `BrandScope` hardens every brand-owned
 * query to zero rows (ADR-0029): every query here escapes it deliberately,
 * counts included — a `withCount()` would re-apply the scope on the counted
 * model and read zero (.ai/rules/app-modules-src.md). Because nothing else
 * narrows them, every read and write is held to the admin host's own brands
 * (`ScopesToOperator`): another operator's discipline answers 404.
 */
class DisciplineAdminController
{
    use HasDataTableQuery;
    use ScopesToOperator;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ManageDisciplines->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'team_size', 'created_at'], default: 'name');
        $filters = [
            'brand' => $this->resolveSelectionFilter($request, 'brand', $selection->brandId()),
            'kind' => $this->resolveFilters($request, ['kind' => array_column(DisciplineKind::cases(), 'value')])['kind'],
        ];

        $brandNames = Brand::query()->whereIn('id', $this->operatorBrandIds())->pluck('name', 'id');

        $disciplines = Discipline::withoutBrandScope()
            ->whereIn('brand_id', $this->operatorBrandIds())
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->when($filters['brand'] !== null, fn ($query) => $query->where('brand_id', $filters['brand']))
            ->when($filters['kind'] !== null, fn ($query) => $query->where('kind', $filters['kind']))
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        // A grouped count rather than withCount(): the latter re-applies
        // BrandScope on the counted model and reads zero on this host.
        $competitionCounts = Competition::withoutBrandScope()
            ->selectRaw('discipline_id, count(*) as aggregate')
            ->whereIn('discipline_id', $disciplines->getCollection()->pluck('id'))
            ->groupBy('discipline_id')
            ->pluck('aggregate', 'discipline_id');

        return Inertia::render('admin/disciplines/Index', [
            'disciplines' => $disciplines->through(fn (Discipline $discipline): array => (new DisciplineRowData(
                id: (string) $discipline->getKey(),
                name: $discipline->name,
                slug: $discipline->slug,
                kind: $discipline->kind,
                brandName: $brandNames[$discipline->brand_id] ?? $discipline->brand_id,
                teamSize: $discipline->team_size,
                avgMatchMinutes: $discipline->avg_match_minutes,
                allowDraws: $discipline->allow_draws,
                gameId: $discipline->game_id,
                competitions: (int) ($competitionCounts[$discipline->getKey()] ?? 0),
            ))->toArray()),
            'brands' => $this->operatorBrandOptions(),
            'options' => $this->kindOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function store(Request $request, CreateDiscipline $createDiscipline): RedirectResponse
    {
        Gate::authorize(Permission::ManageDisciplines->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $this->ensureOperatorOwns($brandId);

        $discipline = $createDiscipline->handle($brandId, DisciplineData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discipline created.')]);

        return to_route('admin.disciplines.edit', $discipline->getKey());
    }

    public function edit(string $discipline): Response
    {
        Gate::authorize(Permission::ManageDisciplines->value);

        $model = $this->find($discipline);

        /** @var Brand|null $brand */
        $brand = Brand::query()->whereKey($model->brand_id)->first();

        return Inertia::render('admin/disciplines/Edit', [
            'discipline' => [
                'id' => (string) $model->getKey(),
                'brandName' => $brand->name ?? '—',
                ...DisciplineData::fromModel($model)->toArray(),
            ],
            'options' => $this->kindOptions(),
            // Why the team-size field may be frozen: `UpdateDiscipline` is
            // the authority and refuses the change server-side either way.
            'competitions' => Competition::withoutBrandScope()
                ->where('brand_id', $model->brand_id)
                ->where('discipline_id', $model->getKey())
                ->count(),
        ]);
    }

    public function update(Request $request, string $discipline, UpdateDiscipline $updateDiscipline): RedirectResponse
    {
        Gate::authorize(Permission::ManageDisciplines->value);

        $model = $this->find($discipline);

        $updateDiscipline->handle($model, DisciplineData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discipline saved.')]);

        return to_route('admin.disciplines.edit', $model->getKey());
    }

    private function find(string $discipline): Discipline
    {
        /** @var Discipline $model */
        $model = Discipline::withoutBrandScope()->findOrFail($discipline);

        $this->ensureOperatorOwns($model->brand_id);

        return $model;
    }

    /**
     * The form's two closed sets: the kinds, and the providers a discipline
     * may require everyone to have linked (design §5.6). The select adds
     * "None" itself.
     *
     * @return array{kind: list<string>, linkedProvider: list<array{value: string, label: string}>}
     */
    private function kindOptions(): array
    {
        return [
            'kind' => array_column(DisciplineKind::cases(), 'value'),
            'linkedProvider' => array_map(
                static fn (LinkedProvider $provider): array => ['value' => $provider->value, 'label' => $provider->label()],
                LinkedProvider::cases(),
            ),
        ];
    }
}
