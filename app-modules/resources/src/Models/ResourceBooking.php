<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Database\Factories\ResourceBookingFactory;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * A claim on a resource over a half-open interval `[starts_at, ends_at)`
 * (ADR-0020).
 *
 * The no-overlap invariant is **not enforced here** — it is a Postgres
 * exclusion constraint on the table, because an application check loses to a
 * race it cannot see. This class only reads it back.
 *
 * `holder_type`/`holder_id` name whoever holds the booking by ULID and a
 * short domain string, never a polymorphic Eloquent relation (ADR-0006).
 * Holder types are `registration` and, since Seating, `operator`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $resource_id
 * @property string $holder_type
 * @property string $holder_id
 * @property string|null $registration_id
 * @property string|null $claim_id
 * @property string|null $amendment_id
 * @property string|null $label
 * @property int $quantity
 * @property bool $is_exclusive
 * @property BookingState $state
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceBooking extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ResourceBookingFactory> */
    use HasFactory, HasUlids;

    public const string HOLDER_REGISTRATION = 'registration';

    /**
     * An orga reservation (design §4.3): `holder_id` is the acting user's
     * id, `registration_id` and `claim_id` stay null.
     */
    public const string HOLDER_OPERATOR = 'operator';

    protected $fillable = [
        'resource_id',
        'holder_type',
        'holder_id',
        'registration_id',
        'claim_id',
        'amendment_id',
        'quantity',
        'label',
        'is_exclusive',
        'state',
        'starts_at',
        'ends_at',
        'hold_expires_at',
    ];

    /**
     * @return BelongsTo<BookableResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The booked resource regardless of ambient brand; see
     * `Resource::resourceTypeUnscoped()` for why the Actions read it this way.
     */
    public function resourceUnscoped(): BookableResource
    {
        return $this->resource()->withoutGlobalScopes()->firstOrFail();
    }

    /**
     * The seat claim this booking fulfils, when it came from a `seating`
     * grant (design §4.3). Null for `resource_claim` grants and direct
     * bookings.
     *
     * @return BelongsTo<ResourceClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ResourceClaim::class, 'claim_id');
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->state === BookingState::Held
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->lessThan($now ?? now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BookingState::class,
            'quantity' => 'integer',
            'is_exclusive' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ResourceBookingFactory
    {
        return ResourceBookingFactory::new();
    }
}
