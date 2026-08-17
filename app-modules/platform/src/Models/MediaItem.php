<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property CarbonImmutable|null $variants_generated_at
 * @property string|null $uploaded_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
            'variants_generated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MediaItemFactory
    {
        return MediaItemFactory::new();
    }
}
