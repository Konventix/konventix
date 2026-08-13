<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The per-(brand, year) gapless counter for credit-note numbers.
 *
 * Deliberately a separate table from `InvoiceNumberRange` rather than a
 * discriminator column on it: two independent sequences mean neither counter
 * can perturb the other, and a credit note is identifiable as one from its
 * number alone.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $prefix
 * @property int $year
 * @property int $counter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditNoteNumberRange extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = [
        'prefix',
        'year',
        'counter',
    ];

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
