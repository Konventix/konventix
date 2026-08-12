<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Models\Operator;

/**
 * @extends Factory<Operator>
 */
class OperatorFactory extends Factory
{
    /** @var class-string<Operator> */
    protected $model = Operator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' e.V.',
            'contact_email' => fake()->safeEmail(),
            'address_line' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country_code' => 'DE',
            'vat_id' => null,
            'tax_number' => null,
            'small_business_scheme' => false,
        ];
    }

    /**
     * The §19 UStG small-business election (ADR-0030): invoices carry no VAT
     * line at all — a distinct invoice code path, not a zero-rate line item.
     */
    public function smallBusinessScheme(): static
    {
        return $this->state(['small_business_scheme' => true]);
    }
}
