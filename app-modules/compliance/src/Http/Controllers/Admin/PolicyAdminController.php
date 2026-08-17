<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Actions\CreatePolicy;
use Konventix\Compliance\Actions\UpdatePolicy;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\PolicyMarkdown;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: the policies a brand publishes and their versions
 * (ADR-0029). Until this screen existed, Compliance had no HTTP surface at
 * all — the legal texts every registration depends on were seeder rows.
 *
 * Two capabilities, deliberately separate: `ManagePolicies` writes drafts,
 * `PublishPolicy` makes one binding. Publishing a version that requires
 * consent invalidates everyone's existing agreement and puts the consent
 * gate in front of them on their next visit, which is not the same kind of
 * act as fixing a typo in a draft.
 *
 * Policies are brand-owned (ADR-0010) and the admin host hardens
 * `BrandScope` to zero rows (ADR-0029), so every query here escapes it
 * deliberately — relation reads included (.ai/rules).
 */
class PolicyAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewPolicies->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'created_at'], default: 'name');

        $policies = Policy::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term);
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $ids = $policies->getCollection()->pluck('id');

        $versions = PolicyVersion::withoutBrandScope()
            ->whereIn('policy_id', $ids)
            ->get()
            ->groupBy('policy_id');

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);
        /** @var array<string, string> $brandNames */
        $brandNames = $brands->pluck('name', 'id')->all();

        return Inertia::render('admin/policies/Index', [
            'policies' => $policies->through(function (Policy $policy) use ($brandNames, $versions): array {
                /** @var Collection<int, PolicyVersion> $own */
                $own = $versions->get($policy->getKey(), collect());
                $published = $own->firstWhere('state', PolicyVersionState::Published);

                return [
                    'id' => $policy->getKey(),
                    'name' => $policy->name,
                    'slug' => $policy->slug,
                    'brandName' => $brandNames[$policy->brand_id] ?? '—',
                    'versions' => $own->count(),
                    'publishedVersion' => $published?->version,
                    'requiresConsent' => (bool) $published?->requires_consent,
                    'effectiveAt' => $published?->effective_at?->toDateString(),
                ];
            }),
            'brands' => $this->brandOptions($brands),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    public function store(Request $request, CreatePolicy $createPolicy): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicies->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $policy = $createPolicy($brandId, PolicyData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Policy created.')]);

        return to_route('admin.policies.edit', $policy->getKey());
    }

    public function edit(Request $request, string $policy, PolicyMarkdown $markdown): Response
    {
        Gate::authorize(Permission::ViewPolicies->value);

        $model = $this->find($policy);

        $versions = PolicyVersion::withoutBrandScope()
            ->where('policy_id', $model->getKey())
            ->orderByDesc('created_at')
            ->get();

        // Consent counts per version — the number that makes "this text is
        // frozen" concrete for whoever is looking at the editor.
        $consents = Consent::withoutBrandScope()
            ->whereIn('policy_version_id', $versions->pluck('id'))
            ->whereNull('withdrawn_at')
            ->selectRaw('policy_version_id, count(*) as aggregate')
            ->groupBy('policy_version_id')
            ->pluck('aggregate', 'policy_version_id');

        $selected = $this->selectedVersion($versions, $request->string('version')->toString());

        return Inertia::render('admin/policies/Edit', [
            'policy' => [
                'id' => $model->getKey(),
                'brandName' => Brand::query()->whereKey($model->brand_id)->value('name') ?? '—',
                'slugFrozen' => $versions->contains(
                    fn (PolicyVersion $version): bool => $version->state !== PolicyVersionState::Draft,
                ),
                ...PolicyData::fromModel($model)->toArray(),
            ],
            'versions' => $versions->map(fn (PolicyVersion $version): array => [
                'id' => $version->getKey(),
                'version' => $version->version,
                'state' => $version->state->value,
                'requiresConsent' => $version->requires_consent,
                'effectiveAt' => $version->effective_at?->toDateTimeString(),
                'publishedAt' => $version->published_at?->toDateTimeString(),
                'consents' => (int) ($consents[$version->getKey()] ?? 0),
                'editable' => $version->state === PolicyVersionState::Draft,
            ])->all(),
            'selected' => $selected === null ? null : [
                'id' => $selected->getKey(),
                'state' => $selected->state->value,
                'editable' => $selected->state === PolicyVersionState::Draft,
                'preview' => $markdown->toHtml($selected->content),
                ...PolicyVersionData::fromModel($selected)->toArray(),
            ],
        ]);
    }

    public function update(Request $request, string $policy, UpdatePolicy $updatePolicy): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicies->value);

        $model = $this->find($policy);

        $updatePolicy($model, PolicyData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Policy saved.')]);

        return to_route('admin.policies.edit', $model->getKey());
    }

    private function find(string $policy): Policy
    {
        /** @var Policy */
        return Policy::withoutBrandScope()->findOrFail($policy);
    }

    /**
     * The version the editor opens on: the one asked for, else the draft that
     * is being worked on, else the published text, else nothing at all.
     *
     * @param  EloquentCollection<int, PolicyVersion>  $versions
     */
    private function selectedVersion(EloquentCollection $versions, string $requested): ?PolicyVersion
    {
        if ($requested !== '') {
            $match = $versions->firstWhere('id', $requested);

            if ($match !== null) {
                return $match;
            }
        }

        return $versions->firstWhere('state', PolicyVersionState::Draft)
            ?? $versions->firstWhere('state', PolicyVersionState::Published)
            ?? $versions->first();
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
}
