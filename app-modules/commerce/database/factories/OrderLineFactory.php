<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /** @var class-string<OrderLine> */
    protected $model = OrderLine::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. Amounts form a consistent 19% standard-rate
     * line so a factory-built order sums plausibly in tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'position' => 1,
            'label' => fake()->words(3, true),
            'quantity' => 1,
            'unit_amount_net' => '30.00',
            'tax_class' => TaxClass::Standard,
            'tax_rate' => '19.00',
            'line_net' => '30.00',
            'line_tax' => '5.70',
            'line_gross' => '35.70',
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_id' => $order->id,
        ]);
    }
}
