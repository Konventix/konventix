<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Database\Factories\ResourceTypeFactory;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * A kind of bookable thing at an event — seats, booths, rooms (ADR-0034).
 *
 * Per event only: D-EVT-13 settles that there is no venue-level catalogue at
 * release, so the same physical room at two events is two type rows. That is
 * deliberate — a shared catalogue would need a reuse story nobody has written.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property CapacityMode $mode
 * @property int $hold_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceType extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ResourceTypeFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['event_id', 'name', 'slug', 'mode', 'hold_minutes'];

    /**
     * @return HasMany<BookableResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mode' => CapacityMode::class, 'hold_minutes' => 'integer'];
    }

    protected static function newFactory(): ResourceTypeFactory
    {
        return ResourceTypeFactory::new();
    }
}
