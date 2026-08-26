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
        $settlesId = (string) Str::ulid();

        return [
            'settles_type' => 'registration',
            'settles_id' => $settlesId,
            // Mirrors an initial settlement request, whose key is the
            // settled thing's own ULID (ADR-0036). An amendment's order
            // carries the amendment's ULID instead — use forRequest().
            'request_key' => $settlesId,
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
            'request_key' => $id,
        ]);
    }

    /**
     * A second order against the same settled thing — what an amendment
     * produces (ADR-0036). Needed whenever a test wants two orders on one
     * registration, since ['settles_type','settles_id','request_key'] is
     * unique.
     */
    public function forRequest(string $requestKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_key' => $requestKey,
        ]);
    }

    /**
     * The person the order is owed by (ADR-0044). Not in the definition:
     * an order without a payer is what pre-ADR data looks like, and a test
     * asserting on the payer should say so explicitly.
     */
    public function forPayer(string $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'payer_user_id' => $userId,
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
