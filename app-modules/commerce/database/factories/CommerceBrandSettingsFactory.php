<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Models\CommerceBrandSettings;

/**
 * @extends Factory<CommerceBrandSettings>
 */
class CommerceBrandSettingsFactory extends Factory
{
    /** @var class-string<CommerceBrandSettings> */
    protected $model = CommerceBrandSettings::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reversal_repayment_days' => 7,
        ];
    }
}
