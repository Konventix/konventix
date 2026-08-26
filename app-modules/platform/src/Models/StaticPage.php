<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Database\Factories\StaticPageFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Spatie\LaravelData\DataCollection;

/**
 * A static page belonging to a brand, built from blocks.
 *
 * Platform owns the block-based CMS for brand static pages; modules register
 * their own block types with it rather than building a second CMS
 * (module-map.md). The `blocks` column holds the ordered block list, behind a
 * typed `DataCollection<BlockData>` cast (ADR-0009 forbids free-form blobs).
 *
 * This is also the reference implementation of a brand-owned model: ULID key,
 * `BelongsToBrand`, no manual `where('brand_id', ...)` anywhere.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $slug
 * @property string $title
 * @property DataCollection<int, BlockData>|null $blocks
 * @property Carbon|null $published_at
 */
class StaticPage extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<StaticPageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['slug', 'title', 'blocks', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => DataCollection::class.':'.BlockData::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StaticPageFactory
    {
        return StaticPageFactory::new();
    }

    /**
     * @param  Builder<StaticPage>  $query
     * @return Builder<StaticPage>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }
}
