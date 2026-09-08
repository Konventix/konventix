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
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A brand's connected settlement account (ADR-0007): whose PSP account money
 * for this brand's orders flows into. For Mollie and Unzer that is the
 * brand's own key on this row; for Stripe the row is only the flag that
 * says this brand sells through the one Stripe account the instance holds
 * (ADR-0058 point 3), plus the words the payer sees — `checkout_label` on
 * the button and `statement_descriptor_suffix` on their card statement.
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
 * @property string|null $statement_descriptor_suffix
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

    /** Stripe, on this instance's own account (ADR-0058). */
    public const string PROVIDER_STRIPE = 'stripe';

    /** Mollie, against the organizer's own API key (ADR-0039). */
    public const string PROVIDER_MOLLIE = 'mollie';

    /** Unzer, against the organizer's own private key (ADR-0039). */
    public const string PROVIDER_UNZER = 'unzer';

    /**
     * How much of Stripe's statement descriptor a brand may claim.
     *
     * Stripe caps the account's own prefix plus this suffix at 22 characters
     * together and refuses the charge if they overflow; the prefix cannot be
     * read back since `fetchAccount()` went with Connect (ADR-0058), so the
     * budget is the worst legal case — a 10-character prefix and its
     * separator leave 11, and this keeps 10.
     *
     * Both the enable form and the in-place edit validate against these two
     * constants so the two rules cannot drift apart.
     */
    public const int STATEMENT_DESCRIPTOR_SUFFIX_MAX = 10;

    /**
     * The characters Stripe refuses outright (`<`, `>`, `\`, `"`, `'`), plus
     * its requirement of at least one latin letter.
     */
    public const string STATEMENT_DESCRIPTOR_SUFFIX_PATTERN = '/^[^<>\\\\"\']*[A-Za-z][^<>\\\\"\']*$/';

    protected $fillable = [
        'provider',
        'label',
        'checkout_label',
        'statement_descriptor_suffix',
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
     * Live, connected, and — for Stripe — chargeable at all.
     *
     * A Stripe row is the brand's enablement flag since ADR-0058 point 3:
     * there is no connected account and no capability to wait for, so the
     * only extra question is whether this instance can charge — which takes
     * the secret key *and* the webhook signing secret. Without the key the
     * button would fail at Stripe rather than on our page; without the
     * signing secret it would succeed at Stripe and never settle, because the
     * webhook is the only source of settlement truth (ADR-0039 point 2). Resolved from the container at call time rather
     * than injected: this is a model, and the answer changes the moment an
     * operator saves a key.
     */
    public function isOffered(): bool
    {
        if (! $this->isLive() || $this->connected_at === null) {
            return false;
        }

        if ($this->provider === self::PROVIDER_STRIPE) {
            return app(StripeCredentials::class)->configured();
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
            // {'private_key': …} for Unzer; Stripe stores no secret here at
            // all — the instance's own key is operator-level, in
            // `StripeSettings` or the environment (ADR-0058 point 2).
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
