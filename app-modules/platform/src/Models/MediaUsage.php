<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A usage reference: which module/entity references a media item
 * (ADR-0035). Written through `Media\MediaLibrary::recordUsage()` /
 * `releaseUsage()` by the consuming module; the library refuses to delete
 * an item while any reference exists.
 *
 * `module`/`entity_type` are short domain strings, `entity_id` a plain
 * ULID — never a PHP class-string (ADR-0014's polymorphic-reference
 * stance).
 *
 * No factory: rows exist only through the MediaLibrary API.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $media_item_id
 * @property string $module
 * @property string $entity_type
 * @property string $entity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaUsage extends Model
{
    use BelongsToBrand, HasUlids;

    protected $fillable = ['media_item_id', 'module', 'entity_type', 'entity_id'];

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
