<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\PolicyBindingFactory;
use Konventix\Compliance\Enums\PolicyBindingKind;
use Konventix\Compliance\Models\Concerns\MayBeOperatorWide;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * What one policy governs (ADR-0066, spec §5.1): a `kind` and a
 * `target_key`, and nothing about what either of them means.
 *
 * A policy with **no** bindings applies everywhere its brand sells — today's
 * behaviour, unchanged (D-CPL-04). A policy *with* bindings applies where
 * they match, combining as **OR within a kind and AND across kinds**
 * (D-CPL-03); `Bindings\PolicyResolver` is the one place that rule is
 * implemented.
 *
 * `brand_id` may be null, exactly as `Policy`'s may (#76, D-CPL-05): a brand
 * binding the operator's inherited text to its own event carries its brand,
 * the operator binding globally carries null. `MayBeOperatorWide` is what
 * keeps `BelongsToBrand` from stamping the resolved brand onto a row that
 * deliberately has none.
 *
 * The binding hangs on the **policy**, never on a version (T-4): rebinding a
 * text is not a new version of it.
 *
 * A binding whose target no longer exists — a deleted type, a disconnected
 * provider — is not an error. It simply never matches, and the console warns
 * (spec §9.2).
 *
 * @property string $id
 * @property string|null $brand_id
 * @property string $policy_id
 * @property PolicyBindingKind $kind
 * @property string $target_key
 * @property string|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PolicyBinding extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PolicyBindingFactory> */
    use HasFactory, HasUlids, MayBeOperatorWide;

    /**
     * Overrides `BelongsToBrand`'s boot hook so a deliberately operator-wide
     * binding keeps its null `brand_id` (see MayBeOperatorWide).
     */
    public static function bootBelongsToBrand(): void
    {
        static::bootOperatorWideBrandScope();
    }

    protected $fillable = [
        'policy_id',
        'kind',
        'target_key',
        'created_by_user_id',
    ];

    /**
     * Every binding of the given policies, brand-owned and operator-wide
     * alike — the resolver's one query.
     *
     * `withoutBrandScope()` deliberately (ADR-0010): an operator-wide
     * binding carries no `brand_id` at all and is invisible to the scoped
     * query, and the resolution is also asked from the operator admin host
     * where `BrandScope` hardens to zero rows (ADR-0029).
     *
     * The brand filter is then restated here rather than left to the scope:
     * a binding belongs to the resolution when it is the operator's or this
     * brand's own, and never when it is another brand's.
     *
     * @param  list<string>  $policyIds
     * @return Builder<static>
     */
    public static function forPolicies(array $policyIds, string $brandId): Builder
    {
        return static::withoutBrandScope()
            ->whereIn('policy_id', $policyIds)
            ->where(fn (Builder $query): Builder => $query
                ->where('brand_id', $brandId)
                ->orWhereNull('brand_id'));
    }

    /**
     * @return BelongsTo<Policy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PolicyBindingKind::class,
        ];
    }

    protected static function newFactory(): PolicyBindingFactory
    {
        return PolicyBindingFactory::new();
    }
}
