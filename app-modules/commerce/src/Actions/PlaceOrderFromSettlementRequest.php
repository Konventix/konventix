<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * Creates the order (and, for `settlement: invoice`, the invoice) from a
 * settlement request's scalar payload — without reading a single
 * Registration model (SPEC.md "Consumes", ADR-0004, ADR-0006).
 *
 * Takes primitives, not the event object: the thin
 * `CreateOrderOnSettlementRequested` listener unpacks it, mirroring
 * Admission's issuance pattern.
 *
 * Idempotent per settlement *request*, not per settled thing: a replayed
 * request finds the existing order (via `withoutBrandScope` scoped to the
 * payload's own `brandId`, ADR-0010's escape hatch) and creates nothing,
 * while a genuinely new request against the same registration creates a
 * second order. ADR-0036 made that distinction load-bearing — an amendment
 * is a delta order against a registration that already has one, and
 * "`settles_id` exists" would have swallowed it.
 *
 * Currency comes from the brand (fixed per brand — decision log Round 6,
 * D-BATCH); tax treatment from the operator's election and the billing
 * party's VAT evidence (ADR-0030), both resolved through Platform's
 * `OperatorSettings` doorway rather than any foreign Eloquent model.
 */
final class PlaceOrderFromSettlementRequest
{
    private const string SETTLES_REGISTRATION = 'registration';

    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
        private readonly IssueInvoice $issueInvoice,
    ) {}

    /**
     * @param  array{
     *     organization_name: string|null,
     *     recipient_name: string|null,
     *     email: string|null,
     *     address_line: string|null,
     *     postal_code: string|null,
     *     city: string|null,
     *     country_code: string|null,
     *     vat_id: string|null,
     * }  $billingParty
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>  $lines
     */
    public function handle(
        string $registrationId,
        string $brandId,
        string $settlement,
        array $billingParty,
        array $lines,
        string $priceDisplay,
        int $termsDays,
        string $requestKey,
        ?string $payerUserId = null,
    ): Order {
        $existing = Order::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('settles_type', self::SETTLES_REGISTRATION)
            ->where('settles_id', $registrationId)
            ->where('request_key', $requestKey)
            ->first();

        if ($existing !== null) {
            // A replay that finally names the payer (ADR-0044) fills the
            // column rather than creating a second order.
            if ($existing->payer_user_id === null && $payerUserId !== null) {
                $existing->forceFill(['payer_user_id' => $payerUserId])->save();
            }

            return $existing;
        }

        return DB::transaction(function () use ($registrationId, $brandId, $settlement, $billingParty, $lines, $priceDisplay, $termsDays, $requestKey, $payerUserId): Order {
            $seller = $this->operatorSettings->sellerForBrand($brandId);
            $party = BillingPartyData::ofEventPayload($billingParty);

            $calculation = $this->taxCalculator->calculate(
                lines: $lines,
                operatorSmallBusinessScheme: $seller->smallBusinessScheme,
                billingVatId: $party->vatId,
                billingCountryCode: $party->countryCode,
            );

            $order = new Order([
                'settles_type' => self::SETTLES_REGISTRATION,
                'settles_id' => $registrationId,
                'request_key' => $requestKey,
                'payer_user_id' => $payerUserId,
                'status' => OrderStatus::Pending,
                'currency' => $this->operatorSettings->currencyForBrand($brandId),
                'total_net' => $calculation->totalNet,
                'total_tax' => $calculation->totalTax,
                'total_gross' => $calculation->totalGross,
                'tax_treatment' => $calculation->treatment,
                'price_display' => $priceDisplay,
            ]);
            // brand_id from the payload, not ambient context (ADR-0010).
            $order->brand_id = $brandId;
            $order->save();

            foreach ($calculation->lines as $index => $line) {
                $orderLine = $order->lines()->make([
                    'position' => $index + 1,
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'unit_amount_net' => $line->unitAmountNet,
                    'tax_class' => $line->taxClass,
                    'tax_rate' => $line->taxRate,
                    'line_net' => $line->lineNet,
                    'line_tax' => $line->lineTax,
                    'line_gross' => $line->lineGross,
                ]);
                $orderLine->brand_id = $brandId;
                $orderLine->save();
            }

            event(new OrderPlaced(
                orderId: $order->id,
                brandId: $brandId,
                settlesType: self::SETTLES_REGISTRATION,
                settlesId: $registrationId,
                totalNet: $calculation->totalNet,
                totalGross: $calculation->totalGross,
                currency: $order->currency,
                placedAt: now()->toIso8601String(),
            ));

            if ($settlement === 'invoice') {
                $this->issueInvoice->handle($order, $party, $seller, $termsDays);
            }

            return $order;
        });
    }
}
