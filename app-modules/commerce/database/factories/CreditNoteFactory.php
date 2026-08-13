<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Order;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    /** @var class-string<CreditNote> */
    protected $model = CreditNote::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'invoice_id' => null,
            'credit_note_number' => 'GS-TEST-'.now()->format('Y').'-'.fake()->unique()->numerify('#####'),
            'billing_party' => null,
            'seller' => null,
            'total_net' => '100.00',
            'total_tax' => '19.00',
            'total_gross' => '119.00',
            'tax_treatment' => TaxTreatment::Standard,
            'currency' => 'EUR',
            'reason_code' => IssueCreditNote::REASON_REFUND,
            'reason_note' => null,
            'issued_on' => now(),
        ];
    }
}
