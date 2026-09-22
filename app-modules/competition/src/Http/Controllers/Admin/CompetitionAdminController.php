<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\CancelCompetition;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\CreateCompetition;
use Konventix\Competition\Actions\FinishCompetition;
use Konventix\Competition\Actions\PublishCompetition;
use Konventix\Competition\Actions\ReopenSignup;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Actions\UnpublishCompetition;
use Konventix\Competition\Actions\UpdateCompetition;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Data\CompetitionData;
use Konventix\Competition\Data\CompetitionRowData;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: competitions (design §9) and the lifecycle an organizer
 * drives them through (D-CMP-15, §4).
 *
 * The form is one `Data` object for the competition **and its first stage**
 * (§7.1) — the format lives on the stage, and `competitions.format` is gone.
 * The lifecycle is deliberately not a form field: it moves through
 * `/transition`, which resolves the one Action that owns the arrow the
 * organizer clicked and lets `CompetitionStatus::allowedTransitions()`
 * refuse anything else. A single endpoint rather than seven is how the state
 * machine stays in one place; the Actions still do the work, one per verb.
 *
 * Events come from Platform's `ConsoleEventSources` (the Event module
 * registers the only source) rather than from Event's tables — this module
 * keeps no event read model, and ADR-0004 rules out reading another
 * module's models.
 *
 * Runs on the admin host, where `BrandScope` hardens every brand-owned
 * query to zero rows (ADR-0029): every query escapes it deliberately, and
 * counts are grouped queries rather than `withCount()`, which would
 * re-apply the scope on the counted model (.ai/rules/app-modules-src.md).
 */
class CompetitionAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection, ConsoleEventSources $sources): Response
    {
        Gate::authorize(Permission::ViewCompetitions->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'status', 'starts_at', 'created_at'], default: 'starts_at');
        $filters = [
            'brand' => $this->resolveSelectionFilter($request, 'brand', $selection->brandId()),
            'event' => $this->resolveSelectionFilter($request, 'event', $selection->eventId()),
            'status' => $this->resolveFilters($request, ['status' => array_column(CompetitionStatus::cases(), 'value')])['status'],
        ];

        $competitions = Competition::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->when($filters['brand'] !== null, fn ($query) => $query->where('brand_id', $filters['brand']))
            ->when($filters['event'] !== null, fn ($query) => $query->where('event_id', $filters['event']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']))
            ->orderBy($sort['column'], $sort['direction'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $ids = $competitions->getCollection()->pluck('id');

        $entrantCounts = Entrant::withoutBrandScope()
            ->selectRaw('competition_id, count(*) as aggregate')
            ->whereIn('competition_id', $ids)
            ->whereIn('state', Entrant::slotHoldingStates())
            ->groupBy('competition_id')
            ->pluck('aggregate', 'competition_id');

        $formats = Stage::withoutBrandScope()
            ->whereIn('competition_id', $ids)
            ->orderBy('sequence')
            ->get(['competition_id', 'format'])
            ->groupBy('competition_id')
            ->map(fn ($stages): string => (string) $stages->first()->format);

        $disciplineNames = Discipline::withoutBrandScope()
            ->whereIn('id', $competitions->getCollection()->pluck('discipline_id')->unique())
            ->pluck('name', 'id');

        $eventNames = collect($sources->eventsFor($filters['brand']))->pluck('name', 'id');

        return Inertia::render('admin/competitions/Index', [
            'competitions' => $competitions->through(function (Competition $competition) use (
                $entrantCounts,
                $formats,
                $disciplineNames,
                $eventNames,
            ): array {
                [$nextStatus, $nextLabel] = $this->nextTransition($competition->status);

                return (new CompetitionRowData(
                    id: (string) $competition->getKey(),
                    name: $competition->name,
                    slug: $competition->slug,
                    status: $competition->status,
                    eventName: $eventNames[$competition->event_id] ?? $competition->event_id,
                    disciplineName: $disciplineNames[$competition->discipline_id] ?? $competition->discipline_id,
                    stageFormat: $formats[$competition->getKey()] ?? '—',
                    entrants: (int) ($entrantCounts[$competition->getKey()] ?? 0),
                    maxEntrants: $competition->max_entrants,
                    startsAt: $competition->starts_at?->toIso8601String(),
                    nextStatus: $nextStatus,
                    nextLabel: $nextLabel,
                ))->toArray();
            }),
            'brands' => $this->brandOptions(),
            'events' => $sources->eventsFor(null),
            'options' => $this->formOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function create(ConsoleEventSources $sources, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ManageCompetitions->value);

        return Inertia::render('admin/competitions/Create', [
            'brands' => $this->brandOptions(),
            'events' => $sources->eventsFor(null),
            'disciplines' => $this->disciplineOptions(),
            'options' => $this->formOptions(),
            'selection' => [
                'brandId' => $selection->brandId(),
                'eventId' => $selection->eventId(),
            ],
        ]);
    }

    public function store(Request $request, CreateCompetition $createCompetition, ConsoleEventSources $sources): RedirectResponse
    {
        Gate::authorize(Permission::ManageCompetitions->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $data = CompetitionData::validateAndCreate($request->all());

        $this->guardEvent($sources, $brandId, $data->eventId);

        $competition = $createCompetition->handle($brandId, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Competition created as a draft.')]);

        return to_route('admin.competitions.edit', $competition->getKey());
    }

    public function edit(string $competition, ConsoleEventSources $sources): Response
    {
        Gate::authorize(Permission::ViewCompetitions->value);

        $model = $this->find($competition);
        $stage = $this->firstStage($model);

        /** @var Brand|null $brand */
        $brand = Brand::query()->whereKey($model->brand_id)->first();

        return Inertia::render('admin/competitions/Edit', [
            'competition' => [
                'id' => (string) $model->getKey(),
                'brandName' => $brand->name ?? '—',
                'status' => $model->status->value,
                'publishedAt' => $model->published_at?->toIso8601String(),
                'stageGenerated' => $stage?->isGenerated() ?? false,
                'entrants' => Entrant::countSlotHolders($model->brand_id, (string) $model->getKey()),
                ...CompetitionData::ofCompetition($model, $stage)->toArray(),
            ],
            'events' => $sources->eventsFor($model->brand_id),
            'disciplines' => $this->disciplineOptions($model->brand_id),
            'options' => $this->formOptions(),
            'transitions' => $this->transitionOptions($model->status),
        ]);
    }

    public function update(
        Request $request,
        string $competition,
        UpdateCompetition $updateCompetition,
        ConsoleEventSources $sources,
    ): RedirectResponse {
        Gate::authorize(Permission::ManageCompetitions->value);

        $model = $this->find($competition);
        $data = CompetitionData::validateAndCreate($request->all());

        $this->guardEvent($sources, $model->brand_id, $data->eventId);

        $updateCompetition->handle($model, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Competition saved.')]);

        return to_route('admin.competitions.edit', $model->getKey());
    }

    /**
     * One endpoint for the whole lifecycle (design §4). The body names the
     * state the organizer is moving to; this resolves the Action that owns
     * that arrow and lets the Action's own transition check refuse. An
     * illegal move comes back as a field error rather than a 500 — the
     * button that offered it was rendered from a page that has since gone
     * stale, which is a thing to be told, not a crash.
     */
    public function transition(Request $request, string $competition): RedirectResponse
    {
        Gate::authorize(Permission::ManageCompetitions->value);

        $model = $this->find($competition);

        $target = CompetitionStatus::from($request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_column(CompetitionStatus::cases(), 'value'))],
        ])['status']);

        try {
            $this->applyTransition($model, $target);
        } catch (IllegalCompetitionStateTransitionException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Competition is now :status.', [
            'status' => str_replace('_', ' ', $target->value),
        ])]);

        return back();
    }

    private function applyTransition(Competition $competition, CompetitionStatus $target): void
    {
        match (true) {
            // Two arrows arrive at `Published`: the first announcement and
            // the reopen from `SignupClosed` (§4). They emit different
            // events, so where the competition stands decides which Action
            // runs.
            $target === CompetitionStatus::Published && $competition->status === CompetitionStatus::SignupClosed => app(ReopenSignup::class)->handle($competition),
            $target === CompetitionStatus::Published => app(PublishCompetition::class)->handle($competition),
            $target === CompetitionStatus::Draft => app(UnpublishCompetition::class)->handle($competition),
            $target === CompetitionStatus::SignupClosed => app(CloseSignup::class)->handle($competition),
            $target === CompetitionStatus::Running => app(StartCompetition::class)->handle($competition),
            $target === CompetitionStatus::Finished => app(FinishCompetition::class)->handle($competition),
            $target === CompetitionStatus::Cancelled => app(CancelCompetition::class)->handle($competition),
        };
    }

    private function find(string $competition): Competition
    {
        /** @var Competition */
        return Competition::withoutBrandScope()->findOrFail($competition);
    }

    private function firstStage(Competition $competition): ?Stage
    {
        /** @var Stage|null */
        return Stage::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->orderBy('sequence')
            ->first();
    }

    /**
     * The event has to be one the console knows for that brand. There is no
     * read model to check against and no reading of Event's tables
     * (ADR-0004), so the same source the picker was rendered from is the
     * authority — the shape `ConsoleSelectionController` uses.
     */
    private function guardEvent(ConsoleEventSources $sources, string $brandId, string $eventId): void
    {
        $known = collect($sources->eventsFor($brandId))->firstWhere('id', $eventId);

        if ($known === null || $known['brandId'] !== $brandId) {
            throw ValidationException::withMessages([
                'eventId' => __('That event is not one of this brand\'s.'),
            ]);
        }
    }

    /**
     * The disciplines the form may offer, with their brand so the create
     * form can narrow them to the brand being picked.
     *
     * @return list<array{id: string, name: string, brandId: string, teamSize: int}>
     */
    private function disciplineOptions(?string $brandId = null): array
    {
        return array_values(Discipline::withoutBrandScope()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->orderBy('name')
            ->get()
            ->map(fn (Discipline $discipline): array => [
                'id' => (string) $discipline->getKey(),
                'name' => $discipline->name,
                'brandId' => $discipline->brand_id,
                'teamSize' => $discipline->team_size,
            ])
            ->all());
    }

    /**
     * Every closed set the form offers, from the enums and the format
     * registry themselves — a sixth format is a registration, never an edit
     * here (ADR-0019).
     *
     * @return array<string, list<string>>
     */
    private function formOptions(): array
    {
        return [
            'status' => array_column(CompetitionStatus::cases(), 'value'),
            'stageFormat' => app(FormatRegistry::class)->keys(),
            'checkInMode' => array_column(CheckInMode::cases(), 'value'),
            'resultSubmissionMode' => array_column(ResultSubmissionMode::cases(), 'value'),
            'proofPolicy' => array_column(ProofPolicy::cases(), 'value'),
        ];
    }

    /**
     * Every arrow out of the current state, labelled for a button (§4).
     *
     * @return list<array{status: string, label: string}>
     */
    private function transitionOptions(CompetitionStatus $status): array
    {
        return array_map(
            fn (CompetitionStatus $target): array => [
                'status' => $target->value,
                'label' => $this->transitionLabel($status, $target),
            ],
            $status->allowedTransitions(),
        );
    }

    /**
     * The forward arrow only: what the list offers as one button.
     *
     * @return array{0: CompetitionStatus|null, 1: string|null}
     */
    private function nextTransition(CompetitionStatus $status): array
    {
        $next = match ($status) {
            CompetitionStatus::Draft => CompetitionStatus::Published,
            CompetitionStatus::Published => CompetitionStatus::SignupClosed,
            CompetitionStatus::SignupClosed => CompetitionStatus::Running,
            CompetitionStatus::Running => CompetitionStatus::Finished,
            CompetitionStatus::Finished, CompetitionStatus::Cancelled => null,
        };

        return [$next, $next === null ? null : $this->transitionLabel($status, $next)];
    }

    /** Console copy is English (ADR-0001 is about brand sites, not this). */
    private function transitionLabel(CompetitionStatus $from, CompetitionStatus $to): string
    {
        return match (true) {
            $to === CompetitionStatus::Published && $from === CompetitionStatus::SignupClosed => 'Reopen signup',
            $to === CompetitionStatus::Published => 'Publish',
            $to === CompetitionStatus::Draft => 'Unpublish',
            $to === CompetitionStatus::SignupClosed => 'Close signup',
            $to === CompetitionStatus::Running => 'Start',
            $to === CompetitionStatus::Finished => 'Finish',
            $to === CompetitionStatus::Cancelled => 'Cancel',
        };
    }
}
