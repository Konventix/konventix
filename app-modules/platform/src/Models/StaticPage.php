<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\StaticPageFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A static page belonging to a brand, built from blocks.
 *
 * Platform owns the block-based CMS for brand static pages; modules register
 * their own block types with it rather than building a second CMS
 * (module-map.md). The `blocks` column holds the ordered block list — a JSON
 * column is acceptable here only because it will sit behind a typed cast
 * (ADR-0009 forbids free-form blobs), which arrives with the block registry.
 *
 * This is also the reference implementation of a brand-owned model: ULID key,
 * `BelongsToBrand`, no manual `where('brand_id', ...)` anywhere.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $slug
 * @property string $title
 * @property array<int, mixed> $blocks
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
            // TODO: replace with a typed block-collection cast once the block
            // registry exists — ADR-0009 forbids untyped structures.
            'blocks' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StaticPageFactory
    {
        return StaticPageFactory::new();
    }
}
