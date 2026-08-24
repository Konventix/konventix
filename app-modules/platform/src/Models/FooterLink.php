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
 * @property string $id
 * @property string $operator_id
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
     * @param  Builder<FooterLink>  $query
     * @return Builder<FooterLink>
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('label');
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
