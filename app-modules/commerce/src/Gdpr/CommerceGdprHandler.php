<?php

declare(strict_types=1);

namespace Konventix\Commerce\Gdpr;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;

/**
 * Commerce's contribution to GDPR export and deletion (ADR-0044, ADR-0047).
 *
 * Export is by `orders.payer_user_id`, the column the settlement request
 * names, so nothing here reads Registration's tables (ADR-0004); Task 11
 * adds the subject's own carts alongside the orders, by the same
 * `payer_user_id`.
 *
 * Deletion erases nothing about an order, in either mode, and that is the
 * decision: an issued invoice is retained under § 147 AO (Art. 17(3)(b)
 * GDPR), the order it was issued for is the commercial fact behind it, and
 * the payer link stays because Identity tombstones the account row itself —
 * severing it here would make a retained invoice unfindable in a later
 * audit. This is about export, not erasure (ROADMAP "Known gaps", now
 * closed).
 *
 * An *open* cart is different: it holds no commercial fact yet, only a
 * reservation, so Task 11 deletes it for real — releasing a product line's
 * stock and emitting `CartLineRemoved` for a registration line exactly the
 * way `RemoveCartLine` does, `withoutBrandScope()` throughout since this
 * handler has no ambient brand context (`ExpireCarts`' own pattern, not a
 * reuse of the Action, which assumes a resolved brand). A `checked_out` or
 * `expired` cart is left alone: the former is already an order (the
 * retention case above), and the latter already released its own stock
 * through the sweep.
 */
final class CommerceGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'commerce';
    }

    public function exportFor(string $subjectId, string $brandId): array
    {
        $orders = Order::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('payer_user_id', $subjectId)
            ->with('lines')
            ->orderBy('created_at')
            ->get();

        $orderIds = $orders->pluck('id')->all();

        $invoices = Invoice::withoutBrandScope()->whereIn('order_id', $orderIds)->orderBy('issued_on')->get()->groupBy('order_id');
        $payments = Payment::withoutBrandScope()->whereIn('order_id', $orderIds)->orderBy('created_at')->get()->groupBy('order_id');
        $creditNotes = CreditNote::withoutBrandScope()->whereIn('order_id', $orderIds)->orderBy('issued_on')->get()->groupBy('order_id');

        $carts = Cart::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('payer_user_id', $subjectId)
            ->with('lines')
            ->orderBy('created_at')
            ->get();

        return [
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->getKey(),
                'settles_type' => $order->settles_type,
                'settles_id' => $order->settles_id,
                'status' => $order->status->value,
                'currency' => $order->currency,
                'total_net' => $order->total_net,
                'total_tax' => $order->total_tax,
                'total_gross' => $order->total_gross,
                'placed_at' => $order->created_at?->toIso8601String(),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'lines' => $order->lines->map(fn ($line): array => [
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'line_gross' => $line->line_gross,
                ])->all(),
                'invoices' => ($invoices[$order->getKey()] ?? collect())->map(fn (Invoice $invoice): array => [
                    'invoice_number' => $invoice->invoice_number,
                    'issued_on' => $invoice->issued_on->toDateString(),
                    'due_on' => $invoice->due_on->toDateString(),
                    'total_gross' => $invoice->total_gross,
                    'tax_treatment' => $invoice->tax_treatment->value,
                    'billing_party' => $invoice->billing_party->toArray(),
                ])->all(),
                'payments' => ($payments[$order->getKey()] ?? collect())->map(fn (Payment $payment): array => [
                    'provider' => $payment->provider,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'received_at' => $payment->received_at?->toIso8601String(),
                    'failed_at' => $payment->failed_at?->toIso8601String(),
                ])->all(),
                'credit_notes' => ($creditNotes[$order->getKey()] ?? collect())->map(fn (CreditNote $note): array => [
                    'credit_note_number' => $note->credit_note_number,
                    'issued_on' => $note->issued_on->toDateString(),
                    'total_gross' => $note->total_gross,
                    'reason_code' => $note->reason_code,
                ])->all(),
            ])->all(),
            'carts' => $carts->map(fn (Cart $cart): array => [
                'id' => $cart->getKey(),
                'status' => $cart->status->value,
                'expires_at' => $cart->expires_at->toIso8601String(),
                'lines' => $cart->lines->map(fn (CartLine $line): array => [
                    'kind' => $line->kind->value,
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'unit_amount_net' => $line->unit_amount_net,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * Orders and invoices stay untouched in both modes — see the class
     * docblock. An *open* cart is genuinely deleted: it holds a
     * reservation, not a commercial fact, so each of its lines is released
     * exactly `RemoveCartLine` would release it — a product line's stock,
     * a registration line's `CartLineRemoved` (which is what tells
     * Registration to give up the still-pending registration it was
     * holding, ADR-0047 point 3) — before the cart row itself is dropped.
     * `checked_out`/`expired` carts are left alone: one is already an
     * order, the other already released its stock through the sweep.
     */
    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
    {
        $openCarts = Cart::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('payer_user_id', $subjectId)
            ->open()
            ->get();

        foreach ($openCarts as $cart) {
            DB::transaction(function () use ($cart): void {
                $lines = CartLine::withoutBrandScope()->where('cart_id', $cart->getKey())->get();

                foreach ($lines as $line) {
                    if ($line->kind === CartLineKind::Product) {
                        $variant = ProductVariant::withoutBrandScope()
                            ->whereKey($line->ref_id)
                            ->lockForUpdate()
                            ->first();

                        $variant?->releaseStock($line->quantity);

                        continue;
                    }

                    event(new CartLineRemoved(
                        cartId: $cart->getKey(),
                        brandId: $cart->brand_id,
                        kind: $line->kind->value,
                        refId: $line->ref_id,
                        requestKey: $line->request_key,
                        removedAt: now()->toIso8601String(),
                    ));
                }

                // FK cascade removes the (already-processed) line rows.
                $cart->delete();
            });
        }
    }
}
