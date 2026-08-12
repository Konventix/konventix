<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /** @var class-string<Order> */
    protected $model = Order::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `settles_id` is a plain ULID (ADR-0014) — it
     * deliberately does not create a row in whatever module owns
     * `settles_type`, since Commerce never touches another module's models.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'settles_type' => 'registration',
            'settles_id' => (string) Str::ulid(),
            'status' => OrderStatus::Pending,
            'currency' => 'EUR',
            'total_net' => fake()->randomFloat(2, 10, 500),
            'total_gross' => fake()->randomFloat(2, 10, 500),
            'paid_at' => null,
        ];
    }

    public function settles(string $type, string $id): static
    {
        return $this->state(fn (array $attributes): array => [
            'settles_type' => $type,
            'settles_id' => $id,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
        ]);
    }
}
