<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\PaymentReversalFactory;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One reversal of settled money (ADR-0057 point 1): a Rücklastschrift, a
 * card chargeback, a dispute the payer opened, or a refund the operator
 * made in the provider's own dashboard. A record of its own rather than a
 * state on the payment — the provider reference, the reason and the fee
 * have nowhere to live on a status column, and a dispute's open/decided
 * lifecycle fits no terminal status.
 *
 * Unique per `(provider, provider_reference)`, so a replayed notification
 * converges on one row exactly as ADR-0039 point 3 makes payments
 * converge. `RecordPaymentReversal` is the only writer.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $payment_id
 * @property string $order_id
 * @property string|null $settlement_account_id
 * @property string $provider
 * @property ReversalKind $kind
 * @property ReversalStatus $status
 * @property numeric-string $amount
 * @property string $currency
 * @property string $provider_reference
 * @property string|null $reason_code
 * @property numeric-string|null $fee_amount
 * @property Carbon $occurred_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $repay_due_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentReversal extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PaymentReversalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'payment_id',
        'order_id',
        'settlement_account_id',
        'provider',
        'kind',
        'status',
        'amount',
        'currency',
        'provider_reference',
        'reason_code',
        'fee_amount',
        'occurred_at',
        'finalized_at',
        'repay_due_on',
    ];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The row a provider notification refers to — the unique key of
     * ADR-0057 point 1. Escapes BrandScope explicitly (ADR-0010), for the
     * reason `Payment::forProviderPayment` does: webhooks arrive on the
     * stateless api surface, where no brand is resolved from the host.
     */
    public static function forProviderReference(string $provider, string $reference): ?self
    {
        return self::withoutBrandScope()
            ->where('provider', $provider)
            ->where('provider_reference', $reference)
            ->first();
    }

    public function isFinal(): bool
    {
        return $this->status === ReversalStatus::Final;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReversalKind::class,
            'status' => ReversalStatus::class,
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'finalized_at' => 'datetime',
            'repay_due_on' => 'date',
        ];
    }

    protected static function newFactory(): PaymentReversalFactory
    {
        return PaymentReversalFactory::new();
    }
}
