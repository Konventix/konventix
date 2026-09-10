<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Database\Factories\ResourceFactory;

/**
 * One bookable thing. In unit mode that is literally one seat; in quantity
 * mode it is one divisible resource whose `capacity` bookings draw down
 * (ADR-0034).
 *
 * `venue_id` is a plain ULID: Event owns venues, and Resources models only
 * the bookable units inside one (ADR-0004, ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $resource_type_id
 * @property string|null $venue_id
 * @property string $label
 * @property int $capacity
 * @property bool $is_published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Resource extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ResourceFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['resource_type_id', 'venue_id', 'label', 'capacity', 'is_published'];

    /**
     * @return BelongsTo<ResourceType, $this>
     */
    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    /**
     * @return HasMany<ResourceBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(ResourceBooking::class);
    }

    /**
     * The type regardless of ambient brand — for Actions that run with no
     * brand in context (a webhook-driven confirmation, the hold sweep) and
     * for the operator host, where BrandScope hardens relation reads to
     * nothing (ADR-0029, `.ai/rules/app-modules-src.md`).
     */
    public function resourceTypeUnscoped(): ResourceType
    {
        return $this->resourceType()->withoutGlobalScopes()->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_published' => 'boolean'];
    }

    protected static function newFactory(): ResourceFactory
    {
        return ResourceFactory::new();
    }
}
