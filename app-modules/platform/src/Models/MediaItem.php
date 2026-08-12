<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\MediaItemFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One uploaded file in the brand-scoped media library (ADR-0035). Other
 * modules store this row's ULID and resolve it through
 * `Media\MediaLibrary` — never a path, never an Eloquent relation across
 * the module boundary (ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $alt_text
 * @property array<string, string> $variants
 * @property string|null $uploaded_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaItem extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<MediaItemFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'alt_text',
        'variants',
        'uploaded_by_user_id',
    ];

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'variants' => 'array',
        ];
    }

    protected static function newFactory(): MediaItemFactory
    {
        return MediaItemFactory::new();
    }
}
