<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Database\Factories\InvoiceFactory;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Platform\Data\OperatorSellerData;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An issued invoice — immutable once issued (ADR-0031): its number is drawn
 * from the brand's gapless range, its amounts and parties are snapshots,
 * and a correction is a credit note or a new invoice, never an edit. The
 * only columns written after issuance are the document fields, which the
 * generation job fills exactly once.
 *
 * `seller` casts to Platform's `OperatorSellerData` — a Data contract, not
 * an Eloquent model, so the cross-module model boundary (ADR-0004) is
 * untouched.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $order_id
 * @property string $invoice_number
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property Carbon|null $overdue_at
 * @property int $dunning_level
 * @property Carbon|null $dunning_raised_at
 * @property string $currency
 * @property numeric-string $total_net
 * @property numeric-string $total_tax
 * @property numeric-string $total_gross
 * @property TaxTreatment $tax_treatment
 * @property string $price_display
 * @property BillingPartyData $billing_party
 * @property OperatorSellerData $seller
 * @property string|null $document_path
 * @property string|null $document_sha256
 * @property Carbon|null $document_generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invoice extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'order_id',
        'invoice_number',
        'issued_on',
        'due_on',
        'currency',
        'total_net',
        'total_tax',
        'total_gross',
        'tax_treatment',
        'price_display',
        'billing_party',
        'seller',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'tax_treatment' => TaxTreatment::class,
            'billing_party' => BillingPartyData::class,
            'seller' => OperatorSellerData::class,
            'document_generated_at' => 'datetime',
            // Dunning state is *about* the invoice, not part of what the
            // document says — ADR-0031's immutability covers the latter.
            'overdue_at' => 'datetime',
            'dunning_level' => 'integer',
            'dunning_raised_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
