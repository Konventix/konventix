<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\CreditNoteLineFactory;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One credited line of a credit note (#7, D-0.8.2-1): which invoice line,
 * how many units of it, and the net/tax/gross that quantity came to at the
 * line's own snapshotted rate.
 *
 * A snapshot, like everything else a credit note carries (ADR-0031): the
 * label and the rate are copied at issuance so the document re-renders
 * identically after the order line has been repriced, relabelled or the
 * statutory rate has moved.
 *
 * It is also the credited-quantity ledger — `creditedQuantityFor()` is the
 * read that makes "this line is already fully credited" refusable while the
 * invoice's total still has room.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $credit_note_id
 * @property string $order_line_id
 * @property int $position
 * @property string $label
 * @property int $quantity
 * @property numeric-string $unit_amount_net
 * @property TaxClass $tax_class
 * @property numeric-string $tax_rate
 * @property numeric-string $line_net
 * @property numeric-string $line_tax
 * @property numeric-string $line_gross
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditNoteLine extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CreditNoteLineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'credit_note_id',
        'order_line_id',
        'position',
        'label',
        'quantity',
        'unit_amount_net',
        'tax_class',
        'tax_rate',
        'line_net',
        'line_tax',
        'line_gross',
    ];

    /**
     * How many units of each of these order lines earlier credit notes have
     * already credited, keyed by `order_line_id` (#7's per-line half of the
     * third acceptance criterion).
     *
     * `withoutBrandScope()` scoped to the caller's own brand is ADR-0010's
     * named escape hatch: a refund is made from the operator host, which
     * resolves no brand, and a relation read would silently come back empty
     * there (`.ai/rules/app-modules-src.md`).
     *
     * @param  list<string>  $orderLineIds
     * @return array<string, int>
     */
    public static function creditedQuantitiesFor(string $brandId, array $orderLineIds): array
    {
        if ($orderLineIds === []) {
            return [];
        }

        /** @var array<string, int> $credited */
        $credited = self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('order_line_id', $orderLineIds)
            ->selectRaw('order_line_id, sum(quantity) as credited')
            ->groupBy('order_line_id')
            ->pluck('credited', 'order_line_id')
            ->map(fn (mixed $quantity): int => (int) $quantity)
            ->all();

        return $credited;
    }

    /**
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'tax_class' => TaxClass::class,
            'unit_amount_net' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_net' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'line_gross' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CreditNoteLineFactory
    {
        return CreditNoteLineFactory::new();
    }
}
