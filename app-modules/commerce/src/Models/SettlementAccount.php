<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\SettlementAccountFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand's connected settlement account (ADR-0007): whose PSP account money
 * for this brand's orders flows into.
 *
 * The platform never holds funds, so this is not a wallet — it is the
 * identity an adapter acts on behalf of. `SettlementAdapter::markOrderPaid()`
 * has taken an `$accountId` since Phase 1 precisely so no adapter could ever
 * be written that acts without one; this is what that id finally refers to.
 *
 * A brand with no row settles through the `manual` provider, which is the
 * honest description of booking a bank transfer by hand — not a degraded
 * state, and the only mode the definition of done actually requires.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $provider
 * @property string|null $label
 * @property string|null $provider_account_id
 * @property array<string, string>|null $credentials
 * @property Carbon|null $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SettlementAccount extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<SettlementAccountFactory> */
    use HasFactory, HasUlids;

    /**
     * Booking a payment by hand. The fallback when a brand has connected
     * nothing, and a legitimate permanent choice for organizers who invoice.
     */
    public const string PROVIDER_MANUAL = 'manual';

    /** A brand that deliberately settles nothing through the platform. */
    public const string PROVIDER_NULL = 'null';

    /** Stripe, direct charges on the organizer's connected account (ADR-0039). */
    public const string PROVIDER_STRIPE = 'stripe';

    /** Mollie, against the organizer's own API key (ADR-0039). */
    public const string PROVIDER_MOLLIE = 'mollie';

    /** Unzer, against the organizer's own private key (ADR-0039). */
    public const string PROVIDER_UNZER = 'unzer';

    protected $fillable = [
        'provider',
        'label',
        'provider_account_id',
        'credentials',
        'connected_at',
        'disconnected_at',
    ];

    /**
     * The account a brand currently settles through, or null when it has
     * connected none. `withoutBrandScope()` scoped explicitly (ADR-0010):
     * settlement runs from queue workers and the operator host.
     */
    public static function liveFor(string $brandId): ?self
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('disconnected_at')
            ->first();
    }

    public function isLive(): bool
    {
        return $this->disconnected_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Structured per provider (ADR-0039): {'api_key': …} for Mollie,
            // {'private_key': …} for Unzer; Stripe stores no secret at all —
            // the connected account id plus the platform key is sufficient.
            'credentials' => 'encrypted:array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SettlementAccountFactory
    {
        return SettlementAccountFactory::new();
    }
}
