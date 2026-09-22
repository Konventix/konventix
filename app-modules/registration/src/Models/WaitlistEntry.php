<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Database\Factories\WaitlistEntryFactory;
use Konventix\Registration\Enums\WaitlistState;

/**
 * One queued submission on a type's waitlist (D-REG-12) — the only
 * waitlist in the system; Resources points here. Carries the full billing
 * snapshot so conversion recreates the submission verbatim, and since
 * D-0.8.2-2 (#8) the `price_declaration` that was on the type when the
 * registrant joined the queue, so the conversion settles at the price they
 * saw rather than at the price the type happens to carry weeks later. State
 * moves only through the waitlist Actions (`JoinWaitlist`,
 * `PromoteFromWaitlist`, `ExpireWaitlistOffers`, conversion inside
 * `SubmitRegistration`).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_type_id
 * @property string $event_id
 * @property string $registrant_user_id
 * @property WaitlistState $state
 * @property string|null $organization_name
 * @property string|null $billing_recipient_name
 * @property string|null $billing_email
 * @property string|null $billing_address_line
 * @property string|null $billing_postal_code
 * @property string|null $billing_city
 * @property string|null $billing_country_code
 * @property string|null $billing_vat_id
 * @property PriceDeclarationData|null $price_declaration
 * @property Carbon $queued_at
 * @property Carbon|null $offered_at
 * @property Carbon|null $offer_expires_at
 * @property string|null $converted_registration_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WaitlistEntry extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_type_id',
        'event_id',
        'registrant_user_id',
        'state',
        'organization_name',
        'billing_recipient_name',
        'billing_email',
        'billing_address_line',
        'billing_postal_code',
        'billing_city',
        'billing_country_code',
        'billing_vat_id',
        'price_declaration',
        'queued_at',
        'offered_at',
        'offer_expires_at',
        'converted_registration_id',
    ];

    /**
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
            'state' => WaitlistState::class,
            // The submitted price, pinned (D-0.8.2-2). Same value object and
            // same cast as `RegistrationType::price_declaration` (ADR-0017)
            // — the queue must not invent a second price concept. Null means
            // "nothing was pinned": a free type, or a row queued before the
            // column existed.
            'price_declaration' => PriceDeclarationData::class,
            'queued_at' => 'datetime',
            'offered_at' => 'datetime',
            'offer_expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WaitlistEntryFactory
    {
        return WaitlistEntryFactory::new();
    }
}
