<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Models\Cart;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /** @var class-string<Cart> */
    protected $model = Cart::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `payer_user_id` is a plain ULID (ADR-0014) —
     * it deliberately does not create a row in Identity, since Commerce
     * never touches another module's models.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payer_user_id' => (string) Str::ulid(),
            'status' => CartStatus::Open,
            'expires_at' => now()->addMinutes(Cart::LIFETIME_MINUTES),
            'checked_out_order_id' => null,
        ];
    }

    public function forPayer(string $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'payer_user_id' => $userId,
        ]);
    }

    /**
     * Still `open`, but its reservation has lapsed — the shape
     * `Actions\ExpireCarts`' sweep looks for. Distinct from an actually
     * `expired` cart, which the sweep has already finished with.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CartStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
