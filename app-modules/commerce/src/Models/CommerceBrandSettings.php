<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\CommerceBrandSettingsFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Per-brand Commerce settings (ADR-0057 point 4). Today one column: the
 * repayment window a reversed payment gets before
 * `commerce:cancel-unpaid-reversals` cancels the order —
 * `Settlement\RepaymentWindow` is the only production reader.
 *
 * `forBrand()` hands back an unsaved defaulted instance when nothing was
 * configured yet, the same idiom `WaitingRoomSettings::forBrand()` uses:
 * every brand has a window whether or not anyone ever opened the form, and
 * a sweep must never depend on a row existing.
 *
 * @property string $id
 * @property string $brand_id
 * @property int $reversal_repayment_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommerceBrandSettings extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CommerceBrandSettingsFactory> */
    use HasFactory, HasUlids;

    protected $table = 'commerce_brand_settings';

    protected $fillable = [
        'reversal_repayment_days',
    ];

    protected $attributes = [
        'reversal_repayment_days' => 7,
    ];

    /**
     * `withoutBrandScope()` — both readers run where no brand is resolved
     * from the host: the operator console (ADR-0029) and the webhook that
     * computes `repay_due_on` on the stateless api surface (ADR-0010).
     */
    public static function forBrand(string $brandId): self
    {
        $existing = self::withoutBrandScope()->where('brand_id', $brandId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $settings = new self;
        $settings->brand_id = $brandId;

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reversal_repayment_days' => 'integer',
        ];
    }

    protected static function newFactory(): CommerceBrandSettingsFactory
    {
        return CommerceBrandSettingsFactory::new();
    }
}
