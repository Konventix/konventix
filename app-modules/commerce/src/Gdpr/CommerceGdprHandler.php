<?php

declare(strict_types=1);

namespace Konventix\Commerce\Gdpr;

use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;

/**
 * Commerce's contribution to GDPR export and deletion (ADR-0044).
 *
 * Export is by `orders.payer_user_id`, the column the settlement request
 * names, so nothing here reads Registration's tables (ADR-0004).
 *
 * Deletion erases nothing, in either mode, and that is the decision: an
 * issued invoice is retained under § 147 AO (Art. 17(3)(b) GDPR), the order
 * it was issued for is the commercial fact behind it, and the payer link
 * stays because Identity tombstones the account row itself — severing it
 * here would make a retained invoice unfindable in a later audit. This is
 * about export, not erasure (ROADMAP "Known gaps", now closed).
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
        ];
    }

    /**
     * Deliberately a no-op in both modes — see the class docblock.
     */
    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void {}
}
