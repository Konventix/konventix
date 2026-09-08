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
use Konventix\Compliance\Data\CheckoutLegalTextGapData;
use Konventix\Compliance\Data\CheckoutLegalTextRoleData;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
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

        $shadows = $this->shadowCounts($policies->getCollection());

        return Inertia::render('admin/policies/Index', [
            'policies' => $policies->through(function (Policy $policy) use ($brandNames, $shadows, $versions): array {
                /** @var Collection<int, PolicyVersion> $own */
                $own = $versions->get($policy->getKey(), collect());
                $published = $own->firstWhere('state', PolicyVersionState::Published);
                $operatorWide = $policy->isOperatorWide();

                return [
                    'id' => $policy->getKey(),
                    'name' => $policy->name,
                    'slug' => $policy->slug,
                    // Which of the two scopes this row is (GitLab #76). A
                    // list that showed only "Brand: —" for the operator's own
                    // texts would read as broken data rather than as the
                    // fallback every brand inherits.
                    'scope' => $operatorWide ? 'operator' : 'brand',
                    'brandName' => $operatorWide
                        ? __('Operator-wide')
                        : ($brandNames[$policy->brand_id] ?? '—'),
                    // How many brands have overridden this operator text —
                    // an operator text binds fewer brands than it looks like
                    // it does once one of them publishes its own.
                    'shadowedByBrands' => $operatorWide ? ($shadows['brandCounts'][$policy->slug] ?? 0) : 0,
                    // ... and, from the other side, whether this brand row is
                    // the override.
                    'shadowsOperator' => ! $operatorWide && in_array($policy->slug, $shadows['operatorSlugs'], true),
                    'versions' => $own->count(),
                    'publishedVersion' => $published?->version,
                    'requiresConsent' => (bool) $published?->requires_consent,
                    'effectiveAt' => $published?->effective_at?->toDateString(),
                ];
            }),
            'brands' => $this->brandOptionsFrom($brands),
            // The convention `CheckoutLegalTexts::SLUGS` is (#200): which
            // two slugs the purchase path looks for, what each one means,
            // and which brands it currently finds nothing under. Until this
            // was on the page, a brand whose AGB lived under `agb` sold
            // without ever asking for an agreement, silently.
            'checkoutRoles' => array_map(
                fn (CheckoutLegalTextRoleData $role): array => $role->toArray(),
                CheckoutLegalTextRoleData::roles(),
            ),
            'checkoutGaps' => array_map(
                fn (CheckoutLegalTextGapData $gap): array => $gap->toArray(),
                $this->checkoutGaps($brands),
            ),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    /**
     * Every brand the checkout resolves no text for, asked through
     * `CheckoutLegalTexts::forBrand()` itself rather than through a query
     * written a second time here: the console must report what the checkout
     * actually sees, including the shadowing rule that makes an unpublished
     * brand policy hide a published operator one.
     *
     * @param  Collection<int, Brand>  $brands
     * @return list<CheckoutLegalTextGapData>
     */
    private function checkoutGaps(Collection $brands): array
    {
        $texts = app(CheckoutLegalTexts::class);

        $gaps = [];

        foreach ($brands as $brand) {
            $resolved = array_column($texts->forBrand((string) $brand->getKey()), 'slug');
            $missing = array_values(array_diff(CheckoutLegalTexts::SLUGS, $resolved));

            if ($missing === []) {
                continue;
            }

            $gaps[] = new CheckoutLegalTextGapData(
                brandId: (string) $brand->getKey(),
                brandName: $brand->name,
                missingSlugs: $missing,
            );
        }

        return $gaps;
    }

    public function store(Request $request, CreatePolicy $createPolicy): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicies->value);

        // Nullable: no brand means the *operator's* copy of a text, which
        // every brand inherits until it publishes its own (GitLab #76). Only
        // reachable from the operator host in the first place (ADR-0029),
        // which is where writing for the whole operator belongs.
        $brandId = $request->validate(['brand_id' => ['nullable', 'ulid', 'exists:brands,id']])['brand_id'] ?? null;
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
                'scope' => $model->isOperatorWide() ? 'operator' : 'brand',
                'brandName' => $model->isOperatorWide()
                    ? __('Operator-wide')
                    : (Brand::query()->whereKey($model->brand_id)->value('name') ?? '—'),
                'slugFrozen' => $versions->contains(
                    fn (PolicyVersion $version): bool => $version->state !== PolicyVersionState::Draft,
                ),
                ...PolicyData::fromModel($model)->toArray(),
            ],
            // Which role this policy plays at checkout, and the whole
            // catalogue beside it so the editor can say what the other one
            // is (#200). Null where the slug plays none — a privacy policy
            // is a policy the purchase path never asks for.
            'checkoutRole' => CheckoutLegalTextRoleData::forSlug($model->slug)?->toArray(),
            'checkoutRoles' => array_map(
                fn (CheckoutLegalTextRoleData $role): array => $role->toArray(),
                CheckoutLegalTextRoleData::roles(),
            ),
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

    /**
     * Which slugs on this page are operator-wide, and how many brand rows
     * exist per slug — the two numbers the "shadowed" columns are read out
     * of. One query, not one per row.
     *
     * Counted across every brand, not only the ones on this page: the point
     * of the column is that an operator text is *not* what some brand reads,
     * and pagination must not change that answer.
     *
     * @param  Collection<int, Policy>  $policies
     * @return array{operatorSlugs: list<string>, brandCounts: array<string, int>}
     */
    private function shadowCounts(Collection $policies): array
    {
        $slugs = $policies->pluck('slug')->unique()->values();

        $rows = Policy::withoutBrandScope()
            ->whereIn('slug', $slugs)
            ->get(['slug', 'brand_id']);

        return [
            'operatorSlugs' => array_values($rows->whereNull('brand_id')->pluck('slug')->unique()->all()),
            'brandCounts' => $rows->whereNotNull('brand_id')
                ->countBy(fn (Policy $policy): string => $policy->slug)
                ->all(),
        ];
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
     * The trait's `brandOptions()` would re-query; this page already holds
     * the brand models, so it maps those. Named apart from the trait method
     * on purpose — an incompatible signature must not shadow it.
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
}
