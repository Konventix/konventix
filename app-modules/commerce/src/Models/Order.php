<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Database\Factories\OrderFactory;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The authoritative record of what was ordered, at what price, with which
 * tax treatment, in which currency (ADR-0006, SPEC.md "Owns" — `Order`) —
 * never a mirror of a payment provider's state.
 *
 * `settles_type`/`settles_id` reference what the order settles — a
 * registration, a resource booking, a shop item — by plain ULID, never an
 * Eloquent relation (ADR-0006, ADR-0014): Commerce never touches another
 * module's models. `settles_type` is a short domain string
 * ("registration", "resource_booking", ...), not a PHP class-string —
 * keeping it independent of any other module's namespace, unlike Laravel's
 * usual polymorphic-relation convention.
 *
 * Per ADR-0026, a `settlement: free` `Registration` never causes a row here
 * to exist at all — Commerce stays dormant until something needs to be paid.
 *
 * `payer_user_id` is the person who owes this order — named by whoever
 * requested settlement (ADR-0044), a plain user ULID, never a relation.
 * Everything that answers "what does this person owe" filters on it.
 *
 * `billing_party` is who an invoice for this order would address (ADR-0048)
 * — a point-in-time snapshot at order placement, the same shape
 * `IssueInvoice` copies onto the invoice itself. Null on an order placed
 * before ADR-0048, or one an operator books manually with no billing
 * information: `IssueInvoiceOnOrderPaid` treats that as nothing to issue,
 * not an error.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $settles_type
 * @property string $settles_id
 * @property string $request_key
 * @property string|null $payer_user_id
 * @property BillingPartyData|null $billing_party
 * @property OrderStatus $status
 * @property string $currency
 * @property string $total_net
 * @property string $total_tax
 * @property string $total_gross
 * @property TaxTreatment $tax_treatment
 * @property string $price_display
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Order extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'settles_type',
        'settles_id',
        'request_key',
        'payer_user_id',
        'billing_party',
        'status',
        'currency',
        'total_net',
        'total_tax',
        'total_gross',
        'tax_treatment',
        'price_display',
        'paid_at',
    ];

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_party' => BillingPartyData::class,
            'status' => OrderStatus::class,
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'tax_treatment' => TaxTreatment::class,
            'paid_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
