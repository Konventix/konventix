<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\FooterLinkFactory;
use Konventix\Platform\Enums\FooterLinkKind;

/**
 * One entry in the operator's footer, shown on every brand-facing page.
 *
 * Operator-owned, not brand-owned: no `BelongsToBrand` here on purpose (see
 * the migration). Brand hosts read these through the operator their brand
 * belongs to.
 *
 * Still not `BelongsToBrand`: `brand_id` narrows an operator-owned list, it
 * does not scope one. The shared prop reads operator rows plus the current
 * brand's, which is a filter over a set the operator owns (ADR-0010's
 * distinction).
 *
 * @property string $id
 * @property string $operator_id
 * @property string|null $brand_id
 * @property string $label
 * @property FooterLinkKind $kind
 * @property string|null $url
 * @property string|null $dialog_key
 * @property bool $opens_in_new_tab
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FooterLink extends Model
{
    /** @use HasFactory<FooterLinkFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'label',
        'kind',
        'url',
        'dialog_key',
        'opens_in_new_tab',
        'position',
    ];

    /**
     * Operator-wide rows first, then a brand's own, each group in its own
     * position/label order — the same ordering the shared footer prop reads
     * with (see HandleInertiaRequests::footerLinks()).
     *
     * @param  Builder<FooterLink>  $query
     * @return Builder<FooterLink>
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw('brand_id IS NOT NULL')->orderBy('position')->orderBy('label');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FooterLinkKind::class,
            'opens_in_new_tab' => 'boolean',
        ];
    }

    protected static function newFactory(): FooterLinkFactory
    {
        return FooterLinkFactory::new();
    }
}
