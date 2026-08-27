<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;

/**
 * @extends Factory<CartLine>
 */
class CartLineFactory extends Factory
{
    /** @var class-string<CartLine> */
    protected $model = CartLine::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. Defaults to a product line — `registration()`
     * below is what Task 9's own test uses to build a registration-kind
     * line directly, since Task 10 is what actually creates them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'kind' => CartLineKind::Product,
            'ref_id' => (string) Str::ulid(),
            'request_key' => null,
            'label' => fake()->words(2, true),
            'quantity' => 1,
            'unit_amount_net' => '10.00',
            'tax_class' => TaxClass::Standard,
            'position' => 0,
        ];
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(fn (array $attributes): array => [
            'cart_id' => $cart->id,
        ]);
    }

    /**
     * A registration-kind line (ADR-0047 point 3, Task 10's shape) — keyed
     * by the settlement request's `request_key` rather than deduped on
     * `ref_id` alone, since a person may hold more than one pending
     * registration at once.
     */
    public function registration(?string $requestKey = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => CartLineKind::Registration,
            'ref_id' => (string) Str::ulid(),
            'request_key' => $requestKey ?? (string) Str::ulid(),
        ]);
    }
}
