<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
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

        $orders = Order::withoutBrandScope()
            ->when($brand !== null, fn ($query) => $query->forBrand($brand))
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

        $invoices = Invoice::withoutBrandScope()
            ->whereIn('order_id', $orders->getCollection()->pluck('id'))
            ->get()
            ->keyBy('order_id');

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders->through(function (Order $order) use ($invoices): array {
                /** @var Invoice|null $invoice */
                $invoice = $invoices->get($order->id);

                return [
                    'id' => $order->getKey(),
                    'settlesType' => $order->settles_type,
                    'settlesId' => $order->settles_id,
                    'status' => $order->status->value,
                    'currency' => $order->currency,
                    'totalNet' => $order->total_net,
                    'totalGross' => $order->total_gross,
                    'taxTreatment' => $order->tax_treatment->value,
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
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brand]),
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
                ])
                ->values()
                ->all(),
        ]);
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
