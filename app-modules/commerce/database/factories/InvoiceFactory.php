<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Data\OperatorSellerData;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /** @var class-string<Invoice> */
    protected $model = Invoice::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. A consistent 19% standard-treatment snapshot
     * matching OrderLineFactory's default line.
     *
     * @return array<string, mixed>
     */
    /**
     * An invoice that actually belongs to its order: the same totals and
     * currency, which is what `IssueInvoice` writes in production. Needed
     * since D-REG-15 gave `IssueCreditNote` a "the sum of an invoice's
     * credit notes never exceeds its gross" guard (#7): a fixture whose
     * invoice says 35.70 while its order says 218.98 refuses a legitimate
     * whole refund.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(function (array $attributes) use ($order): array {
            // The order's gross is the number the guard compares against;
            // net and tax are split back out of it at the standard rate, so
            // the three still agree the way a real invoice's do — the
            // factory's own default does the same for a free-standing row,
            // and `OrderFactory` carries no tax of its own to copy.
            $gross = (string) $order->total_gross;
            $net = bcround(bcdiv($gross, '1.19', 4), 2);

            return [
                'order_id' => $order->id,
                'currency' => $order->currency,
                'total_net' => $net,
                'total_tax' => bcsub($gross, $net, 2),
                'total_gross' => $gross,
            ];
        });
    }

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'invoice_number' => 'TEST-'.now()->format('Y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(14)->toDateString(),
            'currency' => 'EUR',
            'total_net' => '30.00',
            'total_tax' => '5.70',
            'total_gross' => '35.70',
            'tax_treatment' => TaxTreatment::Standard,
            'price_display' => 'gross',
            'billing_party' => new BillingPartyData(
                organizationName: fake()->company(),
                recipientName: fake()->name(),
                email: fake()->safeEmail(),
                addressLine: fake()->streetAddress(),
                postalCode: fake()->postcode(),
                city: fake()->city(),
                countryCode: 'DE',
                vatId: null,
            ),
            'seller' => new OperatorSellerData(
                operatorId: (string) Str::ulid(),
                legalName: fake()->company().' e.V.',
                addressLine: fake()->streetAddress(),
                postalCode: fake()->postcode(),
                city: fake()->city(),
                countryCode: 'DE',
                vatId: null,
                taxNumber: null,
                smallBusinessScheme: false,
            ),
        ];
    }
}
