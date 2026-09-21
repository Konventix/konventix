<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * `quantity` is how many units of the offer this act buys (bugfix round
 * 2026-09-21, Task 19): a buyer-chosen fact set at submission and never
 * edited afterwards. It multiplies the quota units the registration holds,
 * the settlement line's quantity and every grant's own multiplicity —
 * `RegistrationType::max_quantity_per_registration` is the ceiling, and its
 * null default of one is why every older row reads as one.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_type_id
 * @property string $event_id
 * @property string $registrant_user_id
 * @property RegistrationStatus $status
 * @property PaymentStatus $payment_status
 * @property int $quantity
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
 * @property Carbon|null $cart_hold_expires_at
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

    /**
     * A default attribute, not just a column default: `shouldBeStrict()`
     * forbids reading an attribute a freshly created instance never
     * received, and every reader of a registration's quantity — the quota
     * release, the settlement line, the confirmed grants — would otherwise
     * depend on whether the row happened to be re-read after the insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
    ];

    protected $fillable = [
        'registration_type_id',
        'event_id',
        'registrant_user_id',
        'status',
        'payment_status',
        'quantity',
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
     * The declarations made when this act was submitted (ADR-0066, spec
     * §4.3): one row per condition the type demanded at that moment, with the
     * wording that was shown.
     *
     * There is deliberately no row for a condition added to the type
     * afterwards — the console derives *not asserted* from the absence
     * (D-REG-19) — and a row survives the removal of a condition from the
     * type, because it is evidence rather than configuration.
     *
     * @return HasMany<EntryConditionAssertion, $this>
     */
    public function entryConditionAssertions(): HasMany
    {
        return $this->hasMany(EntryConditionAssertion::class, 'registration_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'payment_status' => PaymentStatus::class,
            'quantity' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'cart_hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RegistrationFactory
    {
        return RegistrationFactory::new();
    }
}
