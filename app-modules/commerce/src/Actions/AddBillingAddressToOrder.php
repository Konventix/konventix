<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Commerce\Data\AddOrderBillingAddressData;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\PaymentSnapshotData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * The operator's retrofit for the gap `IssueInvoiceOnOrderPaid` deliberately
 * leaves open (#4): a paid order over `GermanVat::requiresBillingAddress()`'s
 * threshold with no postal address gets no Rechnung at all. An issued
 * invoice is immutable (ADR-0031), so "add the address, then invoice" can
 * only ever mean the invoice did not exist yet — this Action completes the
 * party (a whole-form replace, mirroring Registration's own
 * `UpdateBillingParty`, never a field-by-field merge) and, if the order is
 * already paid, issues the Rechnung immediately in the same transaction —
 * the exact snapshot `IssueInvoiceOnOrderPaid` would have built at
 * `OrderPaid` time, had the address been there already. An order still
 * `pending` just gets its address stored; the listener issues the invoice
 * itself once the order actually pays.
 *
 * Refuses once an invoice exists (ADR-0031): the party a sealed document
 * printed is frozen for good, the same freeze `UpdateBillingParty` applies
 * once a registration's `invoiced_at` is set — correcting it from there
 * needs a credit note and a re-issued invoice, not an edit here.
 *
 * The order row is locked (`lockForUpdate`) before either check, so two
 * concurrent submissions for the same order serialize rather than racing
 * the "does an invoice already exist" check the way `IssueInvoice` itself
 * already guards against a double-issued number.
 */
final class AddBillingAddressToOrder
{
    public function __construct(
        private readonly IssueInvoice $issueInvoice,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    public function handle(Order $order, AddOrderBillingAddressData $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            /** @var Order $locked */
            $locked = Order::withoutBrandScope()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (Invoice::withoutBrandScope()->where('order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'billing' => __('An invoice has already been issued for this order; the billing party is frozen (ADR-0031). Correcting it now needs a credit note and a re-issued invoice.'),
                ]);
            }

            if ($data->organizationName === null && $data->recipientName === null) {
                throw ValidationException::withMessages([
                    'recipientName' => __('Name a recipient or an organization for the invoice.'),
                ]);
            }

            $existing = $locked->billing_party;

            $party = new BillingPartyData(
                organizationName: $data->organizationName,
                recipientName: $data->recipientName,
                email: $existing?->email,
                addressLine: $data->addressLine,
                postalCode: $data->postalCode,
                city: $data->city,
                countryCode: $data->countryCode,
                vatId: $data->vatId,
            );

            $locked->forceFill(['billing_party' => $party])->save();

            if ($locked->status === OrderStatus::Paid) {
                $seller = $this->operatorSettings->sellerForBrand($locked->brand_id);

                // The same lookup `IssueInvoiceOnOrderPaid` makes — the
                // latest succeeded payment is the settlement truth
                // (ADR-0039), not the order's own `paid_at` alone.
                $payment = Payment::withoutBrandScope()
                    ->where('order_id', $locked->id)
                    ->where('status', PaymentStatus::Succeeded)
                    ->latest('received_at')
                    ->first();

                $provider = $payment === null ? SettlementAccount::PROVIDER_MANUAL : $payment->provider;

                $this->issueInvoice->handle(
                    $locked,
                    $party,
                    $seller,
                    termsDays: 0,
                    paid: new PaymentSnapshotData(
                        paidAt: ($locked->paid_at ?? now())->toIso8601String(),
                        provider: $provider,
                        paymentReference: $payment?->external_id,
                    ),
                );
            }

            return $locked->fresh();
        });
    }
}
