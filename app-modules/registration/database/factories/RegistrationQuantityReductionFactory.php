<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationQuantityReduction;

/**
 * @extends Factory<RegistrationQuantityReduction>
 */
class RegistrationQuantityReductionFactory extends Factory
{
    /** @var class-string<RegistrationQuantityReduction> */
    protected $model = RegistrationQuantityReduction::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'units' => 1,
            'reason_code' => 'operator_correction',
            'reason_note' => null,
            'reduced_by_user_id' => null,
            'reduced_at' => now(),
        ];
    }
}
