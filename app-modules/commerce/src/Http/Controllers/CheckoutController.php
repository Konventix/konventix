<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public checkout for `instant` settlement (ADR-0039), addressed by the
 * registration the order settles — the one identifier the payer's flow
 * already carries, and the only coupling Registration needs (a ULID, never
 * a model, ADR-0006). Knowing the unguessable ULID authorizes viewing the
 * order summary, mirroring the waitlist-offer pattern; the page shows
 * lines and totals, never billing data.
 *
 * The page never decides payment truth: it *displays* whatever state the
 * webhook has written, including on return from the PSP — a payer can pay
 * and never return, or return before an async method settles.
 */
class CheckoutController
{
    public function __construct(
        private readonly SettlementAdapterRegistry $registry,
    ) {}

    public function show(string $registration): Response
    {
        $order = $this->orderFor($registration);

        $latestPayment = Payment::query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->first();

        return Inertia::render('pay/Show', [
            'registrationId' => $registration,
            'order' => [
                'id' => $order->id,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'totalNet' => $order->total_net,
                'totalTax' => $order->total_tax,
                'totalGross' => $order->total_gross,
                'lines' => $order->lines->map(fn (OrderLine $line): array => [
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'lineGross' => $line->line_gross,
                ])->all(),
            ],
            'hostedCheckoutAvailable' => $this->hostedCheckoutAvailable($order),
            'paymentStatus' => $latestPayment?->status->value,
        ]);
    }

    public function start(string $registration): SymfonyResponse
    {
        $order = $this->orderFor($registration);

        abort_unless($order->status === OrderStatus::Pending, 409, 'This order is not payable.');

        $account = SettlementAccount::liveFor($order->brand_id);
        $adapter = $this->registry->forBrand($order->brand_id);

        abort_unless($account !== null && $adapter instanceof ProvidesHostedCheckout, 409, 'This brand takes no online payment.');

        $redirect = $adapter->startCheckout(
            $account,
            $order,
            route('commerce.pay.show', ['registration' => $registration]),
        );

        // A hard redirect out of the SPA to the provider's hosted page.
        return Inertia::location($redirect->url);
    }

    /**
     * The latest order settling this registration: ADR-0036 makes "one
     * order per registration" false, and the payable one is the newest.
     * Brand-scoped implicitly — this runs on the brand host.
     */
    private function orderFor(string $registration): Order
    {
        return Order::query()
            ->where('settles_type', 'registration')
            ->where('settles_id', $registration)
            ->latest('created_at')
            ->firstOrFail();
    }

    private function hostedCheckoutAvailable(Order $order): bool
    {
        return SettlementAccount::liveFor($order->brand_id) !== null
            && $this->registry->forBrand($order->brand_id) instanceof ProvidesHostedCheckout;
    }
}
