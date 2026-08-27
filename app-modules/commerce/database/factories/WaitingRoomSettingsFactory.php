<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Models\WaitingRoomSettings;

/**
 * @extends Factory<WaitingRoomSettings>
 */
class WaitingRoomSettingsFactory extends Factory
{
    /** @var class-string<WaitingRoomSettings> */
    protected $model = WaitingRoomSettings::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admit_per_interval' => 50,
            'admission_ttl_minutes' => 30,
        ];
    }
}
