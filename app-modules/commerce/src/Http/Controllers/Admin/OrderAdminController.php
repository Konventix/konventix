<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Actions\CancelOrderForUnpaidReversal;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Data\OrderReversalData;
use Konventix\Commerce\Data\RefundOrderData;
use Konventix\Commerce\Data\UnappliedPaymentData;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Exceptions\RefundEventRelayFailedException;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;
use RuntimeException;

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
                        ->map(fn (PaymentReversal $reversal): array => OrderReversalData::ofReversal($reversal)->toArray())
                        ->values()
                        ->all(),
                    'unappliedPayment' => $unapplied === null
                        ? null
                        : UnappliedPaymentData::ofPayment($unapplied)->toArray(),
                    // ISO instants, formatted in the reader's own zone by
                    // `formatDateTime()` (#208, #238): the console printed
                    // UTC to everyone before this.
                    'paidAt' => $order->paid_at?->toIso8601String(),
                    'createdAt' => $order->created_at?->toIso8601String(),
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
                'paidAt' => $model->paid_at?->toIso8601String(),
                'createdAt' => $model->created_at?->toIso8601String(),
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
                    'receivedAt' => $payment->received_at?->toIso8601String(),
                    'failedAt' => $payment->failed_at?->toIso8601String(),
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
                ->map(fn (PaymentReversal $reversal): array => OrderReversalData::ofReversal($reversal)->toArray())
                ->values()
                ->all(),
            'unappliedPayment' => $unapplied === null
                ? null
                : UnappliedPaymentData::ofPayment($unapplied)->toArray(),
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
            ->whereIn('status', [ReversalStatus::Open->value, ReversalStatus::Final->value])
            // A refund the console itself made (#247) is a decision an
            // operator took, not money to act on: it carries the provider's
            // reference for reconciliation and belongs on the order page,
            // never in the "reversals & unapplied money" work list.
            ->where('kind', '!=', ReversalKind::Refund->value);
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

    /**
     * The operator's manual "cancel now" for a reversed order whose payment
     * never came back (ADR-0057 point 4): `commerce:cancel-unpaid-reversals`
     * does the same thing automatically once `repay_due_on` passes, this
     * lets the operator do it earlier. Same capability as booking a
     * payment — both are treasury actions on a reversed order.
     *
     * `CancelOrderForUnpaidReversal` does not re-guard a checked-in
     * admission the way the sweep does (its own docblock: "An operator who
     * cancels by hand has already made that judgement", the same principle
     * `RefundBlockedByCheckInException` states for the refund path —
     * cancellation is never blocked, only the money flow). This controller
     * adds no guard of its own; the Action's refusal — only a
     * `payment_reversed` order can be cancelled this way — becomes a form
     * error rather than a 500, the shape `CommentController::store` uses.
     */
    public function cancelReversed(string $order, Request $request, CancelOrderForUnpaidReversal $cancel): RedirectResponse
    {
        Gate::authorize(Permission::BookManualPayment->value);

        /** @var Order $model */
        $model = Order::withoutBrandScope()->findOrFail($order);

        try {
            $cancel->handle($model, (string) $request->user()->getAuthIdentifier());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order cancelled.')]);

        return back();
    }

    /**
     * Send the money back for the whole order (#73, ADR-0037). Until now
     * `RefundOrder` was reachable only from tests and tinker; this is the
     * console surface the issue's first open item asks for.
     *
     * Whole order, no amount: `RefundOrder` accepts a partial one, but a
     * partial credit note still splits the tax like the full invoice (#7),
     * so the button that exists is the one that produces a correct
     * document. Transfers and seat release are the issue's other items.
     *
     * Its own capability, deliberately not `BookManualPayment`: taking
     * money in and giving it back are different decisions (ADR-0013,
     * SPEC.md "Capabilities").
     *
     * Every refusal the Action makes is a `RuntimeException` carrying the
     * reason — the ADR-0037 check-in guard, "only a paid order can be
     * refunded", and each adapter's "no refundable payment on this
     * account". They become a form error rather than a 500, the shape
     * `cancelReversed()` above uses: the operator is told what to do about
     * it (check the admission out, book the payment first), which is the
     * whole point of ADR-0037 refusing "explicitly and with a reason".
     *
     * The provider's own refusal used to escape as a 500 (#236): every SDK
     * raises from outside the `RuntimeException` hierarchy, and the operator
     * whose first refund half-failed pressed the button again and got
     * "charge has already been refunded" as a stack trace. Each adapter now
     * translates its SDK into `ProviderRefusedRefundException` (fix round 1,
     * review finding 5), so this reads the same for Stripe, Mollie and Unzer
     * and no SDK is named outside `src/Settlement/<provider>/`. It is caught
     * before the `RuntimeException` arm, because the answer is different: an
     * Action's refusal means nothing moved, while a provider error leaves
     * open whether the money went back — so the message carries the
     * provider's own words and asks for a reconciliation rather than a retry.
     * A rejected key reads as a settings problem instead (review nit 7).
     *
     * A failed *announcement* is the third case and the opposite of a
     * refusal (fix round 1, review NEW-1): `RelayRefundEvents` runs inline
     * under a `sync` queue, so its `RefundEventRelayFailedException` — also a
     * `RuntimeException` — used to land in the arm above and tell the
     * operator 422 about a refund that is recorded and whose money is gone,
     * skipping the audit row with it. The money has moved; the only honest
     * answer is that the refund stands and the follow-up needs looking at.
     * So: audit row written, warning toast, redirect as success.
     *
     * Recording the provider's existing refund automatically is deliberately
     * not attempted: no adapter's seam exposes a way to read a refund back,
     * and inventing a domain refund from an error string would be a document
     * (ADR-0031) built on a guess.
     */
    public function refund(
        string $order,
        RefundOrderData $data,
        RefundOrder $refundOrder,
        AuditRecorder $auditRecorder,
    ): RedirectResponse {
        Gate::authorize(Permission::IssueRefunds->value);

        /** @var Order $model */
        $model = Order::withoutBrandScope()->findOrFail($order);

        $announcementFailed = false;

        try {
            $refundOrder->handle(
                order: $model,
                reasonCode: $data->reasonCode->value,
                reasonNote: $data->reasonNote,
            );
        } catch (ProviderRefusedRefundException $e) {
            throw ValidationException::withMessages([
                'order' => $e->isConfigurationProblem
                    ? __(
                        'This brand\'s :provider account is not usable: :message Nothing was refunded — check the settlement account\'s credentials before trying again.',
                        ['provider' => $e->provider, 'message' => $e->getMessage()],
                    )
                    : __(
                        'The payment provider refused the refund: :message Nothing was recorded here — check the payment in the provider dashboard and reconcile the order by hand if the money did go back.',
                        ['message' => $e->getMessage()],
                    ),
            ]);
        } catch (RefundEventRelayFailedException $e) {
            // Caught before the arm below because it is a `RuntimeException`
            // too, and means the opposite of one: the refund is recorded and
            // the money is back with the payer, only the fan-out inside
            // Konventix failed. Reported here as well as inside the relay, so
            // the request that saw it leaves a trace of its own; then the
            // method carries on to the audit row exactly as a clean refund
            // does.
            report($e);

            $announcementFailed = true;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        // The explicit half of the audit trail (Round 6, D-BATCH), the same
        // call `SettlementAccountAdminController::saveStripeSettings()`
        // makes: `OrderRefunded` is no `AuditableEvent`, so nothing else
        // would record *who* sent the full gross of an order back. #73's
        // "jeder Vorgang erzeugt einen Audit-Eintrag" wants this for the
        // whole flow; this is the refund's share of it.
        //
        // After the Action, never inside it: an audit write is not a
        // business transaction and must not take part in a rollback
        // (`AuditRecorder`'s own docblock). The reason and the note are
        // recorded here in full — this row, not the credit note's document,
        // is where an operator later reads why the money went back.
        $auditRecorder->record(
            action: 'commerce.order.refunded',
            subjectId: $model->getKey(),
            brandId: $model->brand_id,
            context: [
                'reason_code' => $data->reasonCode->value,
                'reason_note' => $data->reasonNote,
                'amount' => (string) $model->total_gross,
                'currency' => $model->currency,
                // The provider's own identifier for the money going back
                // (#247), read off the row `RecordRefund` just wrote. Null
                // for a `manual` refund, which has no provider to give one —
                // and for a refund that reached the announcement failure
                // above, since the row is written before the announcement.
                'provider_reference' => $this->providerRefundReference($model),
                // Whether the seat, the quota and the admission were released
                // with the money. An operator reconstructing "why is this
                // registration still confirmed" reads it here rather than
                // correlating a queue failure by timestamp.
                'announcement_failed' => $announcementFailed,
            ],
        );

        Inertia::flash('toast', $announcementFailed
            // Deliberately says nothing of the underlying error: the operator
            // can act on "it is recorded, the release did not run", not on a
            // consumer's exception message.
            ? ['type' => 'warning', 'message' => __('The refund is recorded and the money is on its way back, but announcing it inside Konventix failed. The release of the seat is being retried — check the failed jobs before assuming it happened.')]
            : ['type' => 'success', 'message' => __('Order refunded.')]);

        return back();
    }

    /**
     * The reference the provider gave the refund this request just made
     * (#247). Newest first, because an order can be refunded more than once
     * over its life — a partial refund followed by the rest.
     */
    private function providerRefundReference(Order $order): ?string
    {
        /** @var string|null $reference */
        $reference = PaymentReversal::withoutBrandScope()
            ->where('order_id', $order->getKey())
            ->where('kind', ReversalKind::Refund->value)
            ->orderByDesc('created_at')
            ->value('provider_reference');

        return $reference;
    }
}
