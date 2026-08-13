<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\BadgeIssuance;

/**
 * @extends Factory<BadgeIssuance>
 */
class BadgeIssuanceFactory extends Factory
{
    /** @var class-string<BadgeIssuance> */
    protected $model = BadgeIssuance::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_id' => Admission::factory(),
            'badge_template_id' => null,
            'issued_by_user_id' => null,
            'reprint' => false,
            'issued_at' => now(),
        ];
    }

    public function reprint(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reprint' => true,
        ]);
    }
}
