<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\CreditNoteLine;
use Konventix\Commerce\Models\OrderLine;

/**
 * @extends Factory<CreditNoteLine>
 */
class CreditNoteLineFactory extends Factory
{
    /** @var class-string<CreditNoteLine> */
    protected $model = CreditNoteLine::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it. Amounts form
     * one consistent 19% unit, matching `OrderLineFactory`'s own defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_note_id' => CreditNote::factory(),
            'order_line_id' => OrderLine::factory(),
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
}
