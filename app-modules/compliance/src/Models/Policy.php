<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\PolicyFactory;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Concerns\MayBeOperatorWide;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A named legal document a brand publishes — terms of participation, privacy
 * policy, house rules, photo consent (SPEC "Owns"). The document text itself
 * lives in this policy's PolicyVersion rows; Policy is just the stable
 * slug/name a version publishes under.
 *
 * `brand_id` may be null, meaning the row belongs to the *operator* and every
 * one of its brands inherits it (GitLab #76). The operator is one legal
 * entity behind all its brands (ADR-0016), so its AGB and
 * Widerrufsbelehrung are written once; a brand that wants its own publishes
 * a policy under the same slug and shadows it. `resolveFor()` is the one
 * place that decides which of the two a brand reads.
 *
 * @property string $id
 * @property string|null $brand_id
 * @property string $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Policy extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PolicyFactory> */
    use HasFactory, HasUlids, MayBeOperatorWide;

    protected $fillable = ['slug', 'name'];

    /**
     * Overrides `BelongsToBrand`'s boot hook so a deliberately operator-wide
     * row keeps its null `brand_id` instead of being stamped with whichever
     * brand happened to be resolved (see MayBeOperatorWide).
     */
    public static function bootBelongsToBrand(): void
    {
        static::bootOperatorWideBrandScope();
    }

    /**
     * The policy one brand reads under a slug: its own if it has one, else
     * the operator's.
     *
     * Resolution is structural, not publication-aware, on purpose. A brand
     * that created its own `terms` policy has taken ownership of that text;
     * serving the operator's text under the brand's slug while the brand is
     * still drafting would publish wording the brand never chose. A brand
     * that wants the operator's text simply does not create its own.
     *
     * Escapes `BrandScope` deliberately (ADR-0010): the operator-wide row
     * carries no `brand_id` at all and is invisible to the scoped query that
     * every public read path otherwise runs.
     */
    public static function resolveFor(string $brandId, string $slug): ?self
    {
        return static::inheritedBy($brandId)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Every policy that brand reads, one row per slug — the brand's own where
     * it has one, the operator's everywhere else.
     *
     * @return Collection<int, static>
     */
    public static function resolvedFor(string $brandId): Collection
    {
        return static::inheritedBy($brandId)->get()->unique('slug')->values();
    }

    /**
     * The version of this policy that binds today: published, and effective.
     * A draft is not a legal text, and a version dated for January must not
     * read as the current terms in December.
     *
     * Escapes `BrandScope` for the same reason `resolveFor()` does — an
     * operator-wide policy's versions carry a null `brand_id` too.
     */
    public function currentVersion(): ?PolicyVersion
    {
        return PolicyVersion::withoutBrandScope()
            ->where('policy_id', $this->getKey())
            ->where('state', PolicyVersionState::Published)
            ->where('effective_at', '<=', now())
            ->first();
    }

    /**
     * The brand's own rows and the operator's, the brand's own first — the
     * ordering `resolveFor()`/`resolvedFor()` both read the shadowing rule
     * out of. Postgres orders `false` before `true`, so `brand_id IS NULL`
     * puts the inherited row last.
     *
     * @return Builder<static>
     */
    protected static function inheritedBy(string $brandId): Builder
    {
        return static::withoutBrandScope()
            ->where(fn (Builder $query): Builder => $query
                ->where('brand_id', $brandId)
                ->orWhereNull('brand_id'))
            ->orderByRaw('brand_id IS NULL')
            ->orderBy('slug');
    }

    /**
     * @return HasMany<PolicyVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }

    /**
     * What this text governs (ADR-0066, spec §5.2). An **empty** relation is
     * the meaningful state: a policy with no bindings applies everywhere its
     * brand sells, which is what every policy did before this table existed
     * (D-CPL-04).
     *
     * A binding of an operator-wide policy may carry a brand of its own
     * (D-CPL-05), so this relation is scoped like any other brand-owned one
     * and the resolver uses `PolicyBinding::forPolicies()` instead — which
     * escapes `BrandScope` deliberately and restates the brand filter.
     *
     * @return HasMany<PolicyBinding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(PolicyBinding::class);
    }

    protected static function newFactory(): PolicyFactory
    {
        return PolicyFactory::new();
    }
}
