<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\SettlementAccountFactory;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
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
 * @property string|null $checkout_label
 * @property string|null $provider_account_id
 * @property array<string, string>|null $credentials
 * @property array<string, mixed>|null $capabilities
 * @property Carbon|null $capabilities_checked_at
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
        'checkout_label',
        'provider_account_id',
        'credentials',
        'capabilities',
        'capabilities_checked_at',
        'connected_at',
        'disconnected_at',
    ];

    /**
     * Every account a brand may settle through, oldest connection first
     * (ADR-0046 — the single answer is gone; callers pick or list).
     * `withoutBrandScope()` scoped explicitly (ADR-0010): settlement runs
     * from queue workers and the operator host. `connected_at`/`created_at`
     * are second-precision columns, so two accounts connected within the
     * same second tie on both — `id` breaks the tie deterministically
     * because `HasUlids` mints ULIDs in monotonically increasing order.
     *
     * @return Collection<int, static>
     */
    public static function liveAccountsFor(string $brandId): Collection
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('disconnected_at')
            ->orderBy('connected_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function isLive(): bool
    {
        return $this->disconnected_at === null;
    }

    /**
     * Live, connected, and — for Stripe — allowed to take cards: a freshly
     * onboarded Stripe account is connected but not yet active, and
     * offering it would fail at Stripe rather than on our page.
     */
    public function isOffered(): bool
    {
        if (! $this->isLive() || $this->connected_at === null) {
            return false;
        }

        if ($this->provider === self::PROVIDER_STRIPE) {
            return ($this->capabilities['card_payments'] ?? null) === 'active';
        }

        return true;
    }

    public function checkoutLabel(SettlementAdapter $adapter): string
    {
        if ($this->checkout_label !== null && trim($this->checkout_label) !== '') {
            return $this->checkout_label;
        }

        return $adapter instanceof ProvidesHostedCheckout ? $adapter->defaultCheckoutLabel() : ucfirst($this->provider);
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
            'capabilities' => 'array',
            'capabilities_checked_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SettlementAccountFactory
    {
        return SettlementAccountFactory::new();
    }
}
