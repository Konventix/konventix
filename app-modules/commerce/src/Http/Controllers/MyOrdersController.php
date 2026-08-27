<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Data\MyOrderData;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;

/**
 * The payer's own orders (ADR-0044) — reached from the account menu.
 *
 * Authorization is ownership: `payer_user_id` is the reader, checked in the
 * query itself. The brand scope narrows first, so an order placed with
 * another brand is not on this host's list.
 */
final class MyOrdersController
{
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
}
