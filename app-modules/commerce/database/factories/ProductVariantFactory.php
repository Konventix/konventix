<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /** @var class-string<ProductVariant> */
    protected $model = ProductVariant::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'label' => null,
            'sku' => null,
            'unit_amount_net' => null,
            'stock_capacity' => 10,
            'stock_consumed' => 0,
            'position' => 0,
        ];
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_capacity' => $capacity,
        ]);
    }

    public function consumed(int $consumed): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_consumed' => $consumed,
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_capacity' => 1,
            'stock_consumed' => 1,
        ]);
    }
}
