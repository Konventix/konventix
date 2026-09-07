<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Database\Factories\PaymentFactory;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One settlement attempt against an order (ADR-0039). The order stays the
 * authoritative money record (ADR-0006); this row is the attempt that
 * settles it, created `pending` at checkout start and transitioned only by
 * a verified webhook. Unique per `(provider, external_id)` so a replayed
 * notification converges on the same row instead of settling twice.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $order_id
 * @property string|null $settlement_account_id
 * @property string $provider
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string|null $external_id
 * @property string|null $provider_transaction_id
 * @property string|null $failure_reason
 * @property Carbon|null $received_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Payment extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id',
        'settlement_account_id',
        'provider',
        'status',
        'amount',
        'currency',
        'external_id',
        'provider_transaction_id',
        'failure_reason',
        'received_at',
        'failed_at',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The row a provider notification refers to. Escapes BrandScope
     * explicitly (ADR-0010): webhooks arrive on the stateless api surface,
     * where no brand is resolved from the host.
     */
    public static function forProviderPayment(string $provider, string $externalId): ?self
    {
        return self::withoutBrandScope()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * The row a reversal notification refers to (ADR-0057 point 7). Stripe
     * signs a dispute or a refund against the *payment intent*, not the
     * checkout session that started it, and `provider_transaction_id` is
     * where that intent was recorded at settlement. Escapes BrandScope for
     * the reason `forProviderPayment` does.
     */
    public static function forProviderTransaction(string $provider, string $transactionId): ?self
    {
        return self::withoutBrandScope()
            ->where('provider', $provider)
            ->where('provider_transaction_id', $transactionId)
            ->first();
    }

    /**
     * Money that arrived for an order that cannot take it (ADR-0057
     * point 9, #6). The data definition is exactly this: the payment
     * succeeded and its order is not paid — a PSP success that landed
     * after `ExpireCarts`' checkout-grace sweep cancelled the order, or
     * after a refund. `PaymentUnapplied` announces the moment; this scope
     * finds every case, including the ones that predate the event.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeUnapplied(Builder $query): Builder
    {
        return $query
            ->where('status', PaymentStatus::Succeeded)
            ->whereExists(fn (QueryBuilder $orders): QueryBuilder => $orders
                ->select(DB::raw('1'))
                ->from('orders')
                ->whereColumn('orders.id', 'payments.order_id')
                ->where('orders.status', '!=', OrderStatus::Paid->value));
    }

    public function isSettled(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    /**
     * Which provider settled each of these orders, keyed by order id: the
     * latest succeeded payment per order (ADR-0046) — the orders console,
     * the order detail and the bookkeeping export all name the money by
     * this same lookup. Escapes BrandScope explicitly (ADR-0010): every
     * caller runs on the operator admin host.
     *
     * @param  iterable<string>  $orderIds
     * @return Collection<string, string>
     */
    public static function latestProviderByOrder(iterable $orderIds): Collection
    {
        return self::withoutBrandScope()
            ->whereIn('order_id', $orderIds)
            ->where('status', PaymentStatus::Succeeded)
            ->orderByDesc('received_at')
            ->get()
            ->unique('order_id')
            ->pluck('provider', 'order_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
