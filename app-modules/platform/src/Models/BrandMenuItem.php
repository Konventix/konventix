<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\BrandMenuItemFactory;
use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entry of a brand's topbar menu, rendered after the module entries
 * the `NavigationRegistry` contributes (see `Navigation\BrandMenu`).
 *
 * Brand-owned — `BelongsToBrand` — because the menu is the brand's own
 * face, unlike the operator-wide footer. One level of hierarchy through
 * `parent_id`; the position is per parent, so the top-level rows and each
 * parent's children are each their own ordered list.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $parent_id
 * @property BrandMenuItemKind $kind
 * @property string|null $label
 * @property string|null $static_page_id
 * @property string|null $url
 * @property bool $opens_in_new_tab
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StaticPage|null $page
 */
class BrandMenuItem extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<BrandMenuItemFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'parent_id',
        'kind',
        'label',
        'static_page_id',
        'url',
        'opens_in_new_tab',
        'position',
    ];

    /**
     * Top-level rows first, then children, each group by position — the
     * order the menu is built in and the order the editor lists it in.
     *
     * @param  Builder<BrandMenuItem>  $query
     * @return Builder<BrandMenuItem>
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw('parent_id IS NOT NULL')->orderBy('position');
    }

    /**
     * @return BelongsTo<StaticPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(StaticPage::class, 'static_page_id');
    }

    /**
     * @return BelongsTo<BrandMenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BrandMenuItemKind::class,
            'opens_in_new_tab' => 'boolean',
        ];
    }

    protected static function newFactory(): BrandMenuItemFactory
    {
        return BrandMenuItemFactory::new();
    }
}
