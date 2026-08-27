<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Database\Factories\CreditNoteFactory;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Platform\Data\OperatorSellerData;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A credit note against an order — the only way to correct an issued invoice,
 * which is immutable (ADR-0031).
 *
 * Serves two flows that were always going to need the same document: refunds
 * (ADR-0037) and post-invoice transfer to a different billing party
 * (ADR-0036). ADR-0036 says as much — "credit notes stop being a refund-only
 * concept … they enter release scope with the refund flow".
 *
 * Numbered from its **own** gapless series with its own prefix, so a credit
 * note is distinguishable from an invoice by its number alone and neither
 * counter can perturb the other. Parties and amounts are snapshots for the
 * same reason an invoice snapshots them: the document must read correctly
 * years later.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $order_id
 * @property string|null $invoice_id
 * @property string $credit_note_number
 * @property BillingPartyData $billing_party
 * @property OperatorSellerData $seller
 * @property numeric-string $total_net
 * @property numeric-string $total_tax
 * @property numeric-string $total_gross
 * @property TaxTreatment $tax_treatment
 * @property string $currency
 * @property string $reason_code
 * @property string|null $reason_note
 * @property Carbon $issued_on
 * @property string|null $document_path
 * @property string|null $document_disk
 * @property string|null $document_sha256
 * @property Carbon|null $document_generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditNote extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory, HasUlids;

    /**
     * The document fields are not fillable — like `Invoice`, they are
     * written exactly once by the generation job via `forceFill()`, never
     * through mass assignment at issuance.
     */
    protected $fillable = [
        'order_id',
        'invoice_id',
        'credit_note_number',
        'billing_party',
        'seller',
        'total_net',
        'total_tax',
        'total_gross',
        'tax_treatment',
        'currency',
        'reason_code',
        'reason_note',
        'issued_on',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_party' => BillingPartyData::class,
            'seller' => OperatorSellerData::class,
            'tax_treatment' => TaxTreatment::class,
            'issued_on' => 'datetime',
            'document_generated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CreditNoteFactory
    {
        return CreditNoteFactory::new();
    }
}
