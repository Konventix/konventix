<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Platform\Database\Factories\BrandDomainFactory;

/**
 * One host a brand answers on. Deliberately a separate table: a brand routinely
 * has several (apex, www, staging, legacy) and host lookup must be indexed.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $host
 * @property bool $is_primary
 */
class BrandDomain extends Model
{
    /** @use HasFactory<BrandDomainFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['host', 'is_primary'];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function newFactory(): BrandDomainFactory
    {
        return BrandDomainFactory::new();
    }
}
