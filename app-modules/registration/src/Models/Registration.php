<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\RegistrationFactory;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationStatus;

/**
 * The contractual act itself (ADR-0005, SPEC.md "Owns" — `Registration`):
 * carries the invoice recipient (which may be an organization rather than
 * the submitting user), the registration status, and — independently — the
 * payment status. Neither status implies the other (SPEC.md "Two
 * independent status fields").
 *
 * `event_id` and `registrant_user_id` are plain ULIDs with no FK: Event and
 * Identity are different modules (ADR-0014). `registrant_user_id` is
 * required, never nullable — D-REG-08 (`docs/inventory/decision-log.md`
 * Round 5): registrants must hold an account in Phase 1, guest registration
 * is deferred.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_type_id
 * @property string $event_id
 * @property string $registrant_user_id
 * @property RegistrationStatus $status
 * @property PaymentStatus $payment_status
 * @property string|null $organization_name
 * @property string|null $billing_recipient_name
 * @property string|null $billing_email
 * @property string|null $billing_address_line
 * @property string|null $billing_postal_code
 * @property string|null $billing_city
 * @property string|null $billing_country_code
 * @property string|null $billing_vat_id
 * @property Carbon $submitted_at
 * @property Carbon|null $approved_at
 * @property string|null $approved_by_user_id
 * @property Carbon|null $rejected_at
 * @property string|null $rejected_by_user_id
 * @property string|null $reason_code
 * @property Carbon|null $invoiced_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Registration extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RegistrationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_type_id',
        'event_id',
        'registrant_user_id',
        'status',
        'payment_status',
        'organization_name',
        'billing_recipient_name',
        'billing_email',
        'billing_address_line',
        'billing_postal_code',
        'billing_city',
        'billing_country_code',
        'billing_vat_id',
        'submitted_at',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'reason_code',
        'confirmed_at',
        'cancelled_at',
        'cancelled_by_user_id',
    ];

    /**
     * Within-module foreign key — RegistrationType is owned by this same
     * module, so an actual FK constraint is fine (ADR-0014).
     *
     * @return BelongsTo<RegistrationType, $this>
     */
    public function registrationType(): BelongsTo
    {
        return $this->belongsTo(RegistrationType::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'payment_status' => PaymentStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RegistrationFactory
    {
        return RegistrationFactory::new();
    }
}
