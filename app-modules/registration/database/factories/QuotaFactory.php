<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Registration\Models\Quota;

/**
 * @extends Factory<Quota>
 */
class QuotaFactory extends Factory
{
    /** @var class-string<Quota> */
    protected $model = Quota::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context, and a test that forgets to establish one
     * should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word().' '.fake()->word().' quota',
            'capacity' => 100,
            'consumed' => 0,
        ];
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (array $attributes): array => [
            'capacity' => $capacity,
        ]);
    }

    public function consumed(int $consumed): static
    {
        return $this->state(fn (array $attributes): array => [
            'consumed' => $consumed,
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'capacity' => 1,
            'consumed' => 1,
        ]);
    }
}
