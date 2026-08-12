<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One row per brand and year: the gapless invoice-number counter
 * (ADR-0006, ADR-0031 — GoBD wants sequential, gapless, unique numbers).
 * Only `Actions\DrawInvoiceNumber` may increment it, under
 * `lockForUpdate()` inside the issuing transaction, so a rolled-back
 * issuance rolls the counter back with it and no number is ever burned.
 *
 * No factory: rows exist only through `DrawInvoiceNumber`'s firstOrCreate —
 * creating one any other way would invite gaps.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $prefix
 * @property int $year
 * @property int $counter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoiceNumberRange extends Model
{
    use BelongsToBrand, HasUlids;

    protected $fillable = ['prefix', 'year', 'counter'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'counter' => 'integer',
        ];
    }
}
