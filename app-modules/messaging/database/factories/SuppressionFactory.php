<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Models\Suppression;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    /** @var class-string<Suppression> */
    protected $model = Suppression::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => Channel::Mail,
            'email' => fake()->safeEmail(),
            'recipient_id' => null,
            'reason_code' => 'hard_bounce',
            'suppressed_at' => now(),
            'lifted_at' => null,
        ];
    }

    public function lifted(): static
    {
        return $this->state(fn (): array => ['lifted_at' => now()]);
    }
}
