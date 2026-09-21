<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\RegistrationQuantityReductionFactory;

/**
 * One partial cancellation of a confirmed registration (D-REG-15): `units`
 * of its `quantity` were given back, and the money for exactly those units
 * with them. Created only by `Actions\ReduceRegistrationQuantity`.
 *
 * The subtractive counterpart of `RegistrationAmendment`, and load-bearing
 * outside this module for the same reason: its ULID is the idempotency key
 * Admission stamps on the admissions it revoked, Resources records against
 * the claim it shrank, and Commerce writes on the credit note — so a
 * replayed `RegistrationQuantityReduced` revokes, releases and refunds
 * nothing a second time.
 *
 * `reduced_by_user_id` is nullable so the column never claims a person
 * acted who did not; the console is its only writer today and always names
 * one.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property int $units
 * @property string $reason_code
 * @property string|null $reason_note
 * @property string|null $reduced_by_user_id
 * @property Carbon $reduced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RegistrationQuantityReduction extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RegistrationQuantityReductionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_id',
        'units',
        'reason_code',
        'reason_note',
        'reduced_by_user_id',
        'reduced_at',
    ];

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'reduced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RegistrationQuantityReductionFactory
    {
        return RegistrationQuantityReductionFactory::new();
    }
}
