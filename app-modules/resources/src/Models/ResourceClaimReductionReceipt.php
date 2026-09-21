<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * "This claim has already been shrunk by that reduction" (D-REG-15) — the
 * idempotency key `ReduceSeatClaim` keys on, since a claim's `quantity` is
 * a running number a replay cannot converge on by itself.
 *
 * Written by `Actions\ReduceSeatClaim` only, never read by anything else.
 * Carries no personal data.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $claim_id
 * @property string $reduction_id
 * @property int $units
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceClaimReductionReceipt extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = [
        'claim_id',
        'reduction_id',
        'units',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
        ];
    }
}
