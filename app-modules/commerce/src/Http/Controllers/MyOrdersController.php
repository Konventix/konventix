<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\MyOrderData;
use Konventix\Commerce\Data\MyOrderDetailData;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Settlement\PayLink;

/**
 * The payer's own orders (ADR-0044) — reached from the account menu.
 *
 * Authorization is ownership: `payer_user_id` is the reader, checked in the
 * query itself. The brand scope narrows first, so an order placed with
 * another brand is not on this host's list.
 *
 * `show()` is one order on its own page (bugfix round 2026-09-21, Task 3):
 * the return URL every hosted checkout is handed since then — the cart
 * used to be, and a cart is `checked_out` by the time the payer comes
 * back, so the landing read "Your cart is empty". Same ownership rule,
 * applied per order the way `CheckoutController::abortUnlessPayer()`
 * applies it on `/pay/order/{order}` (#185): 404 for everyone but the
 * payer, so a stranger holding an id from a mail or a browser history
 * learns nothing.
 */
final class MyOrdersController
{
    public function __construct(private readonly PayLink $payLink) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('payer_user_id', (string) $request->user()->getAuthIdentifier())
            ->with('lines')
            ->latest('created_at')
            ->get();

        // One lookup for the page: the newest invoice per order.
        $invoices = Invoice::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->orderByDesc('issued_on')
            ->get()
            ->unique('order_id')
            ->keyBy('order_id');

        // One lookup for every credit note issued against any of these
        // orders (ADR-0048 point 2) — grouped per order, oldest first, the
        // way `MyCreditNoteData::ofCreditNote()` expects to list them.
        $creditNotes = CreditNote::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->orderBy('issued_on')
            ->get()
            ->groupBy('order_id');

        return Inertia::render('commerce/my/Index', [
            'orders' => $orders
                ->map(function (Order $order) use ($invoices, $creditNotes): array {
                    /** @var list<CreditNote> $orderCreditNotes */
                    $orderCreditNotes = $creditNotes->get($order->getKey(), collect())->values()->all();

                    return MyOrderData::ofOrder($order, $invoices->get($order->getKey()), $orderCreditNotes)->toArray();
                })
                ->all(),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        // Brand-scoped (ADR-0010): another brand's order is already gone
        // before the payer is compared at all.
        /** @var Order|null $model */
        $model = Order::query()->with('lines')->whereKey($order)->first();

        // The ULID addresses the order; the payer authorizes it (#185). A
        // null `payer_user_id` — a manual booking, or an order placed before
        // ADR-0044 — belongs to nobody and refuses everyone.
        abort_unless(
            $model !== null
                && $model->payer_user_id !== null
                && $model->payer_user_id === (string) $request->user()->getAuthIdentifier(),
            404,
        );

        $invoice = Invoice::query()
            ->where('order_id', $model->getKey())
            ->orderByDesc('issued_on')
            ->first();

        /** @var list<CreditNote> $creditNotes */
        $creditNotes = CreditNote::query()
            ->where('order_id', $model->getKey())
            ->orderBy('issued_on')
            ->get()
            ->all();

        // The latest attempt, whatever its state: a page reached from the
        // provider's return URL has to tell "still pending" from "failed"
        // from "nothing tried yet", and only the newest row says which.
        $latestPayment = Payment::query()
            ->where('order_id', $model->getKey())
            ->latest('created_at')
            ->first();

        return Inertia::render('commerce/my/Show', [
            'order' => MyOrderDetailData::ofOrder(
                $model,
                $invoice,
                $creditNotes,
                $latestPayment,
                $this->payLink->repayDueOnFor($model),
            )->toArray(),
        ]);
    }
}
