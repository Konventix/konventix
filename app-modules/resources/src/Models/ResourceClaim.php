<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Database\Factories\ResourceClaimFactory;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;

/**
 * A seating entitlement as Resources knows it (ADR-0060 point 8, D-EVT-06):
 * created by the confirmation listener from a `seating` grant, placed by
 * `AssignPlacesToRegistration`, released with the registration. The
 * categories it may be placed in are its own snapshot (D-EVT-07), so
 * eligibility never needs Registration.
 *
 * Registration and user ids are plain ULIDs (ADR-0014). `registrant_user_id`
 * is what authorises the picker and is nulled on erasure.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $registration_id
 * @property string|null $registrant_user_id
 * @property string|null $amendment_id
 * @property list<string> $resource_type_ids
 * @property SeatingMode $seating_mode
 * @property int $quantity
 * @property ClaimState $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceClaim extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ResourceClaimFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'registration_id',
        'registrant_user_id',
        'amendment_id',
        'resource_type_ids',
        'seating_mode',
        'quantity',
        'state',
    ];

    /**
     * @param  Builder<ResourceClaim>  $query
     * @return Builder<ResourceClaim>
     */
    public function scopeForRegistration(Builder $query, string $registrationId): Builder
    {
        return $query->where($query->qualifyColumn('registration_id'), $registrationId);
    }

    /**
     * @param  Builder<ResourceClaim>  $query
     * @return Builder<ResourceClaim>
     */
    public function scopeUnplaced(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('state'), ClaimState::Unplaced->value);
    }

    /**
     * @return HasMany<ResourceBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(ResourceBooking::class, 'claim_id');
    }

    /**
     * The bookings that occupy a place right now — `held` or `confirmed`,
     * the same predicate the exclusion constraint uses.
     *
     * @return HasMany<ResourceBooking, $this>
     */
    public function activeBookings(): HasMany
    {
        return $this->bookings()->whereIn('state', BookingState::occupyingValues());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource_type_ids' => 'array',
            'seating_mode' => SeatingMode::class,
            'quantity' => 'integer',
            'state' => ClaimState::class,
        ];
    }

    protected static function newFactory(): ResourceClaimFactory
    {
        return ResourceClaimFactory::new();
    }
}
