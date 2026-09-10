<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\QuotaFactory;

/**
 * A standalone entity, not a column on a type (ADR-0005, SPEC.md "Owns" —
 * `Quota`): quotas are consumed and released by registrations and may be
 * referenced by more than one `RegistrationType` through the
 * `registration_type_quota` pivot.
 *
 * `consume()`/`release()` only mutate the counters; callers are responsible
 * for locking the row (`lockForUpdate()`) and running inside the owning
 * Action's `DB::transaction` — see `SubmitRegistration`.
 *
 * `resource_type_id` (design §12b, #282) links this quota to a Resources
 * seat category as its ceiling: nullable, a plain ULID with no FK
 * (ADR-0014, Resources owns `ResourceType`), set from the quota form's
 * category select. `AdjustQuotaOnPlaceSupplyChanged` is the only listener
 * that reads it.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $name
 * @property string|null $resource_type_id
 * @property int $capacity
 * @property int $consumed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Quota extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<QuotaFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'resource_type_id',
        'capacity',
        'consumed',
    ];

    /**
     * @return BelongsToMany<RegistrationType, $this>
     */
    public function registrationTypes(): BelongsToMany
    {
        return $this->belongsToMany(RegistrationType::class, 'registration_type_quota');
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->consumed);
    }

    public function hasCapacityFor(int $quantity = 1): bool
    {
        return $this->remaining() >= $quantity;
    }

    public function consume(int $quantity = 1): void
    {
        $this->consumed += $quantity;
        $this->save();
    }

    public function release(int $quantity = 1): void
    {
        $this->consumed = max(0, $this->consumed - $quantity);
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'consumed' => 'integer',
        ];
    }

    protected static function newFactory(): QuotaFactory
    {
        return QuotaFactory::new();
    }
}
