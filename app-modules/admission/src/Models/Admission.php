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
 * @property string|null $registration_id
 * @property string|null $amendment_id
 * @property string|null $reduction_id
 * @property string|null $assignment_id
 * @property string $event_id
 * @property string|null $holder_user_id
 * @property string|null $buyer_user_id
 * @property string $role
 * @property list<array{key: string, label: string, verification: string, document_hint: string|null}>|null $entry_conditions
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property AdmissionState $state
 * @property string|null $token_nonce_hash
 * @property Carbon|null $token_issued_at
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
        'amendment_id',
        'reduction_id',
        'assignment_id',
        'event_id',
        'holder_user_id',
        'buyer_user_id',
        'role',
        'entry_conditions',
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
            // Opaque scalars stamped at issuance (ADR-0066 D-ADM-06) —
            // this module never interprets a condition, it carries one.
            'entry_conditions' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'state' => AdmissionState::class,
            'token_issued_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AdmissionFactory
    {
        return AdmissionFactory::new();
    }
}
