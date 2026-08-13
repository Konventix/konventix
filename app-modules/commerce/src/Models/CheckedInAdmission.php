<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Commerce's read model of Admission's check-ins (ADR-0037, D-COM-15).
 *
 * This module must never read Admission's tables, so it keeps its own copy,
 * projected from `AdmissionCheckedIn` — whose payload carries
 * `registration_id` specifically to make this possible without a join across
 * a module boundary (ADR-0004, ADR-0006).
 *
 * One row per admission rather than a count per registration: the projection
 * has to converge when events replay or arrive out of order, and a counter
 * would drift on every redelivery. Per-admission keying is also what a future
 * partial-refund-of-unused-admissions flow will need.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $admission_id
 * @property string $registration_id
 * @property Carbon $first_checked_in_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CheckedInAdmission extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'commerce_checked_in_admissions';

    protected $fillable = [
        'admission_id',
        'registration_id',
        'first_checked_in_at',
    ];

    /**
     * The question the refund guard asks (ADR-0037). `withoutBrandScope()`
     * scoped to the caller's own brand is the named escape hatch (ADR-0010):
     * refunds run from the operator host, which resolves no brand.
     */
    public static function registrationHasCheckIn(string $brandId, string $registrationId): bool
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_checked_in_at' => 'datetime',
        ];
    }
}
