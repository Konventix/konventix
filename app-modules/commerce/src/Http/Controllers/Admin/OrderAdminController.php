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
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;

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

    public function index(Request $request): Response
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

        $orders = Order::withoutBrandScope()
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
            'filters' => $this->currentFilters($request, $sort, $perPage),
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

        return to_route('admin.orders.index');
    }
}
