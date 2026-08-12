<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\AdmissionFactory;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person's access right (ADR-0005, SPEC.md "Owns" — `Admission`): a
 * named (or still unnamed) holder, a role, a validity window, and a
 * lifecycle state. Registration is the commercial act; this is the access
 * right it produced. One `Registration` grants N `Admission`s.
 *
 * `registration_id` and `event_id` are plain ULIDs with no FK: Registration
 * and Event are different modules (ADR-0014). This model — and everything
 * else in this module — never imports a `Konventix\Registration` or
 * `Konventix\Commerce` class; see
 * `tests/RegistrationCommerceImportBoundaryTest.php`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property string $event_id
 * @property string|null $holder_user_id
 * @property string $role
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property AdmissionState $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Admission extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<AdmissionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_id',
        'event_id',
        'holder_user_id',
        'role',
        'valid_from',
        'valid_until',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'state' => AdmissionState::class,
        ];
    }

    protected static function newFactory(): AdmissionFactory
    {
        return AdmissionFactory::new();
    }
}
