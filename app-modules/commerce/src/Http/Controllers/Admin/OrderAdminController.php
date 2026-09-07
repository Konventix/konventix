<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: orders and their invoices (ADR-0029). Route middleware
 * already requires the admin host, auth and `platform.access-admin`;
 * per-operation capabilities are checked here.
 *
 * Orders are brand-owned (ADR-0010); the admin host hardens `BrandScope` to
 * nothing (ADR-0029), so the index spans every brand through the explicit
 * `withoutBrandScope()` escape hatch deliberately.
 */
class OrderAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewOrders->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['created_at', 'status', 'total_gross'],
            default: 'created_at',
        );

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        // The console's pick is this list's default, cleared per list with
        // `brand=all` (ADR-0029). No event filter: an order settles a
        // registration, and the list carries no event column to filter on.
        $brand = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        // ADR-0057 point 8's "open reversals" list, and with it point 9's
        // unapplied money: one view rather than two, because both are the
        // same operator task — money the order and the bank disagree about.
        // A filter, not a tab: this list already speaks in toolbar selects.
        $view = $this->resolveFilters($request, ['view' => ['reversals']])['view'];

        $orders = Order::withoutBrandScope()
            ->when($brand !== null, fn ($query) => $query->forBrand($brand))
            ->when($view === 'reversals', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->whereIn('id', $this->reversalQuery()->select('order_id'))
                    ->orWhereIn('id', Payment::withoutBrandScope()->unapplied()->select('order_id')),
            ))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('settles_id', 'ilike', $term)
                        ->orWhereIn('id', Invoice::withoutBrandScope()
                            ->where('invoice_number', 'ilike', $term)
                            ->select('order_id'));
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $orderIds = $orders->getCollection()->pluck('id');

        $invoices = Invoice::withoutBrandScope()
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');

        $providerByOrder = Payment::latestProviderByOrder($orderIds);

        // Read for every view, not only the reversals one: a reversed order
        // has to name its deadline wherever it is listed (ADR-0057 point 3).
        // Both escape BrandScope per query, not just on the parent
        // (`.ai/rules/app-modules-src.md`), and both are keyed by order so
        // the rows stay two queries rather than two per row.
        $reversalsByOrder = $this->reversalQuery()
            ->whereIn('order_id', $orderIds)
            ->orderByDesc('occurred_at')
            ->get()
            ->groupBy('order_id');

        $unappliedByOrder = Payment::withoutBrandScope()
            ->unapplied()
            ->whereIn('order_id', $orderIds)
            ->orderByDesc('received_at')
            ->get()
            ->unique('order_id')
            ->keyBy('order_id');

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders->through(function (Order $order) use ($invoices, $providerByOrder, $reversalsByOrder, $unappliedByOrder): array {
                /** @var Invoice|null $invoice */
                $invoice = $invoices->get($order->id);

                /** @var Payment|null $unapplied */
                $unapplied = $unappliedByOrder->get($order->id);

                return [
                    'id' => $order->getKey(),
                    'settlesType' => $order->settles_type,
                    'settlesId' => $order->settles_id,
                    'status' => $order->status->value,
                    'currency' => $order->currency,
                    'totalNet' => $order->total_net,
                    'totalGross' => $order->total_gross,
                    'taxTreatment' => $order->tax_treatment->value,
                    'provider' => $providerByOrder[$order->id] ?? null,
                    'reversals' => $reversalsByOrder->get($order->id, collect())
                        ->map(fn (PaymentReversal $reversal): array => $this->reversalRow($reversal))
                        ->values()
                        ->all(),
                    'unappliedPayment' => $unapplied === null
                        ? null
                        : $this->unappliedPaymentRow($unapplied),
                    'paidAt' => $order->paid_at?->format('Y-m-d H:i'),
                    'createdAt' => $order->created_at?->format('Y-m-d H:i'),
                    'invoice' => $invoice === null ? null : [
                        'id' => $invoice->getKey(),
                        'number' => $invoice->invoice_number,
                        'dueOn' => $invoice->due_on->toDateString(),
                        'documentReady' => $invoice->document_path !== null,
                    ],
                ];
            }),
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brand, 'view' => $view]),
        ]);
    }

    /**
     * One order end to end: lines, invoice, payment attempts and credit
     * notes. Every related query escapes BrandScope explicitly — relation
     * calls would silently re-apply it on the admin host (ADR-0029).
     */
    public function show(string $order): Response
    {
        Gate::authorize(Permission::ViewOrders->value);

        /** @var Order $model */
        $model = Order::withoutBrandScope()->findOrFail($order);

        /** @var Invoice|null $invoice */
        $invoice = Invoice::withoutBrandScope()->where('order_id', $model->getKey())->first();

        $provider = Payment::latestProviderByOrder([$model->getKey()])->get($model->getKey());

        // ADR-0057 point 9 / #6: a succeeded payment on an order that is not
        // paid. What to do about it is the operator's decision, so the page
        // states the fact and offers no button.
        /** @var Payment|null $unapplied */
        $unapplied = Payment::withoutBrandScope()
            ->unapplied()
            ->where('order_id', $model->getKey())
            ->orderByDesc('received_at')
            ->first();

        return Inertia::render('admin/orders/Show', [
            'order' => [
                'id' => $model->getKey(),
                'brand' => Brand::query()->find($model->brand_id)->name ?? $model->brand_id,
                'settlesType' => $model->settles_type,
                'settlesId' => $model->settles_id,
                'status' => $model->status->value,
                'currency' => $model->currency,
                'totalNet' => $model->total_net,
                'totalTax' => $model->total_tax,
                'totalGross' => $model->total_gross,
                'taxTreatment' => $model->tax_treatment->value,
                'provider' => $provider,
                'paidAt' => $model->paid_at?->format('Y-m-d H:i'),
                'createdAt' => $model->created_at?->format('Y-m-d H:i'),
            ],
            'lines' => OrderLine::withoutBrandScope()
                ->where('order_id', $model->getKey())
                ->orderBy('position')
                ->get()
                ->map(fn (OrderLine $line): array => [
                    'id' => $line->getKey(),
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'unitAmountNet' => $line->unit_amount_net,
                    'taxRate' => $line->tax_rate,
                    'lineNet' => $line->line_net,
                    'lineGross' => $line->line_gross,
                ])
                ->values()
                ->all(),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->getKey(),
                'number' => $invoice->invoice_number,
                'issuedOn' => $invoice->issued_on->toDateString(),
                'dueOn' => $invoice->due_on->toDateString(),
                'totalGross' => $invoice->total_gross,
                'documentReady' => $invoice->document_path !== null,
                'billingParty' => [
                    'name' => $invoice->billing_party->displayName(),
                    'email' => $invoice->billing_party->email,
                    'city' => $invoice->billing_party->city,
                    'countryCode' => $invoice->billing_party->countryCode,
                    'vatId' => $invoice->billing_party->vatId,
                ],
            ],
            'payments' => Payment::withoutBrandScope()
                ->where('order_id', $model->getKey())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->getKey(),
                    'provider' => $payment->provider,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'externalId' => $payment->external_id,
                    'failureReason' => $payment->failure_reason,
                    'receivedAt' => $payment->received_at?->format('Y-m-d H:i'),
                    'failedAt' => $payment->failed_at?->format('Y-m-d H:i'),
                ])
                ->values()
                ->all(),
            // ADR-0057 point 8. Every row the order has, withdrawn disputes
            // included: the console is where an operator reconstructs what
            // the provider did, and a won dispute is part of that story —
            // unlike the index list, which shows only what is still owed.
            'reversals' => PaymentReversal::withoutBrandScope()
                ->where('order_id', $model->getKey())
                ->orderByDesc('occurred_at')
                ->get()
                ->map(fn (PaymentReversal $reversal): array => $this->reversalRow($reversal))
                ->values()
                ->all(),
            'unappliedPayment' => $unapplied === null
                ? null
                : $this->unappliedPaymentRow($unapplied),
            'creditNotes' => CreditNote::withoutBrandScope()
                ->where('order_id', $model->getKey())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (CreditNote $note): array => [
                    'id' => $note->getKey(),
                    'number' => $note->credit_note_number,
                    'totalGross' => $note->total_gross,
                    'reasonCode' => $note->reason_code,
                    'issuedOn' => $note->issued_on->toDateString(),
                    // Mirrors the invoice card's documentReady, above.
                    'documentReady' => $note->document_path !== null,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The reversals an operator still has to act on (ADR-0057 point 8): a
     * dispute the provider has not decided, and every reversal that took
     * the money for good. A withdrawn one moved no money and is no task,
     * which is why the list is a status pair rather than "all rows".
     *
     * Escapes BrandScope on its own, per `.ai/rules/app-modules-src.md`:
     * the admin host hardens the scope to nothing and a subquery is no
     * exception.
     *
     * @return Builder<PaymentReversal>
     */
    private function reversalQuery(): Builder
    {
        return PaymentReversal::withoutBrandScope()
            ->whereIn('status', [ReversalStatus::Open->value, ReversalStatus::Final->value]);
    }

    /**
     * One reversal as both console views show it (ADR-0057 point 1's
     * fields): what kind it is, where it stands, how much left, the
     * provider's own reference and reason, and the day the money is owed
     * back by.
     *
     * @return array<string, string|null>
     */
    private function reversalRow(PaymentReversal $reversal): array
    {
        return [
            'id' => $reversal->getKey(),
            'provider' => $reversal->provider,
            'kind' => $reversal->kind->value,
            'status' => $reversal->status->value,
            'amount' => $reversal->amount,
            'currency' => $reversal->currency,
            'providerReference' => $reversal->provider_reference,
            'reasonCode' => $reversal->reason_code,
            // Only Stripe disputes report one (ADR-0007: the organizer's own
            // cost either way), so the page must not present it as always
            // there.
            'feeAmount' => $reversal->fee_amount,
            'occurredAt' => $reversal->occurred_at->format('Y-m-d H:i'),
            'finalizedAt' => $reversal->finalized_at?->format('Y-m-d H:i'),
            'repayDueOn' => $reversal->repay_due_on?->toDateString(),
        ];
    }

    /**
     * Money that arrived for an order that cannot take it (ADR-0057
     * point 9, #6) — the provider, the amount and when it landed.
     *
     * @return array<string, string|null>
     */
    private function unappliedPaymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'provider' => $payment->provider,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'externalId' => $payment->external_id,
            'receivedAt' => $payment->received_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * Book a manual payment — an organizer marking a bank transfer as
     * received (SPEC.md "Owns"). Deliberately a separate capability from
     * order viewing (ADR-0013).
     */
    public function bookPayment(string $order, BookManualPayment $bookManualPayment): RedirectResponse
    {
        Gate::authorize(Permission::BookManualPayment->value);

        /** @var Order $model */
        $model = Order::withoutBrandScope()->findOrFail($order);

        $bookManualPayment->handle($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment booked.')]);

        // Back to wherever the booking happened — index dialog or detail page.
        return back();
    }
}
