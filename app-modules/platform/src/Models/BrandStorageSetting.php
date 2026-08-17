<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Database\Factories\BrandStorageSettingFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand's own object storage for media (ADR-0043 point 6), mirroring
 * per-brand mail transports (ADR-0028): a brand that brings its own bucket
 * carries that as brand data, not as deployment configuration.
 *
 * Absence is the normal case and a legitimate state — the brand then uses the
 * deployment's configured media disk. Nothing here changes where files
 * *already* live: every media item records its own disk, so a brand switching
 * to its own bucket is a `storage:migrate` away, not a cutover.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $bucket
 * @property string|null $region
 * @property string|null $endpoint
 * @property string $prefix
 * @property bool $use_path_style_endpoint
 * @property bool $public_direct
 * @property string|null $url
 * @property array{key: string, secret: string} $credentials
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BrandStorageSetting extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<BrandStorageSettingFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'bucket',
        'region',
        'endpoint',
        'prefix',
        'use_path_style_endpoint',
        'public_direct',
        'url',
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'use_path_style_endpoint' => 'boolean',
            'public_direct' => 'boolean',
            // Credentials at rest, encrypted — the same stance ADR-0028 takes
            // for a brand's mail transport.
            'credentials' => 'encrypted:array',
        ];
    }

    protected static function newFactory(): BrandStorageSettingFactory
    {
        return BrandStorageSettingFactory::new();
    }
}
