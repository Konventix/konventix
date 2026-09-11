<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Database\Factories\ResourceFactory;

/**
 * One bookable thing. In unit mode that is literally one seat; in quantity
 * mode it is one divisible resource whose `capacity` bookings draw down
 * (ADR-0034).
 *
 * Since ADR-0060 a place of a plan is a unit-mode resource whose id equals
 * the element id in the plan document: `plan_id`, `ref`, `zone_id`,
 * `group_id`, `accessible`, `blocked` and `neighbors` are the projection
 * `SavePlan` maintains, and `is_published` of a plan-placed resource is
 * derived from the plan. Plan-less resources (booths, devices) keep
 * `plan_id` null and the manual flag.
 *
 * Soft-deleted: a removed place keeps its booking history. The default
 * query hides deleted rows; `withTrashed()` is the greppable opt-in.
 *
 * `venue_id` is a plain ULID: Event owns venues, and Resources models only
 * the bookable units inside one (ADR-0004, ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $resource_type_id
 * @property string|null $plan_id
 * @property string|null $venue_id
 * @property string $label
 * @property string|null $ref
 * @property string|null $zone_id
 * @property string|null $group_id
 * @property int $capacity
 * @property bool $is_published
 * @property bool $accessible
 * @property bool $blocked
 * @property list<string>|null $neighbors
 * @property string|null $origin_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Resource extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ResourceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'resource_type_id',
        'plan_id',
        'venue_id',
        'label',
        'ref',
        'zone_id',
        'group_id',
        'capacity',
        'is_published',
        'accessible',
        'blocked',
        'neighbors',
        'origin_id',
    ];

    /**
     * @return BelongsTo<ResourceType, $this>
     */
    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
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
     * What may be booked at all: published and not locked by the operator.
     * Deleted rows are already gone from the default query. Whether it is
     * *free* is a question for `resource_bookings`, never for this row
     * (ADR-0060 point 4).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_published'), true)
            ->where($query->qualifyColumn('blocked'), false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_published' => 'boolean',
            'accessible' => 'boolean',
            'blocked' => 'boolean',
            'neighbors' => 'array',
        ];
    }

    protected static function newFactory(): ResourceFactory
    {
        return ResourceFactory::new();
    }
}
