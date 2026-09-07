<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use RuntimeException;

/**
 * Cancels an order whose reversed payment was never put back (ADR-0057
 * point 4). Two callers: the daily `commerce:cancel-unpaid-reversals` sweep
 * once `repay_due_on` has passed, and the operator cancelling by hand at any
 * point before it — which is why `$cancelledByUserId` is a parameter and not
 * inferred from an ambient session.
 *
 * What it does, in one transaction:
 *
 * - the order moves to `OrderStatus::Cancelled`;
 * - where an invoice exists, a credit note with reason `cancellation`, so
 *   document and counter-document sum to zero. The money side — payment in,
 *   return out — is the PSP statement's (ADR-0040 books documents only), and
 *   the *reversal* itself never gets a credit note: a chargeback says the
 *   payer took the money back, not that the sale was undone;
 * - `OrderCancelled` with reason `reversal_unpaid`, the event Commerce's
 *   SPEC has proposed since ADR-0006, now with a producer;
 * - for a `cart` order, one `OrderLineCancelled` per registration-kind line,
 *   because `OrderCancelled` alone cannot say which of a cart's several
 *   registrations to release and Registration may not read the lines
 *   (ADR-0004). The same iteration `RecordPaymentReversal::emitOrderLineReversed`
 *   does, for the same reason.
 *
 * **This Action does not ask whether anyone checked in.** That question
 * decides whether the *sweep* may cancel at all (`CancelUnpaidReversals`
 * asks it through `GuardsCheckedInAdmissions` and leaves the invoice to
 * dunning instead), not what cancelling means. An operator who cancels by
 * hand has already made that judgement.
 *
 * Idempotent by its guard: an order that is no longer `payment_reversed`
 * cannot be cancelled twice, so a second sweep run finds nothing to do.
 *
 * Every read escapes BrandScope explicitly (ADR-0010): both callers run
 * where no brand is resolved from the host — a console command and the
 * operator admin host.
 */
final class CancelOrderForUnpaidReversal
{
    /**
     * The reason code both events carry. Registration keys its
     * cancellation listeners on exactly this value (ADR-0057 point 5).
     */
    public const string REASON_REVERSAL_UNPAID = 'reversal_unpaid';

    public function __construct(
        private readonly IssueCreditNote $issueCreditNote,
    ) {}

    public function handle(Order $order, ?string $cancelledByUserId = null): Order
    {
        return DB::transaction(function () use ($order, $cancelledByUserId): Order {
            $locked = Order::withoutBrandScope()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardReversed($locked);

            $creditNote = $this->creditNoteFor($locked);

            $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

            $cancelledAt = now();

            event(new OrderCancelled(
                orderId: $locked->id,
                brandId: $locked->brand_id,
                settlesType: $locked->settles_type,
                settlesId: $locked->settles_id,
                cancelledByUserId: $cancelledByUserId,
                reasonCode: self::REASON_REVERSAL_UNPAID,
                creditNoteId: $creditNote?->id,
                cancelledAt: $cancelledAt->toIso8601String(),
            ));

            $this->emitOrderLineCancelled($locked, $cancelledByUserId, $cancelledAt->toIso8601String());

            $order->setRawAttributes($locked->getAttributes());
            $order->syncOriginal();

            return $order;
        });
    }

    /**
     * An order that was never reversed has no unpaid reversal to cancel
     * for, and one that is already `cancelled` must not produce a second
     * credit note. Explicit and reasoned rather than a silent no-op: the
     * operator's console button and the sweep both deserve to know.
     */
    private function guardReversed(Order $order): void
    {
        if ($order->status !== OrderStatus::PaymentReversed) {
            throw new RuntimeException(sprintf(
                'Order [%s] is %s; only a payment_reversed order can be cancelled for an unpaid reversal.',
                $order->id,
                $order->status->value,
            ));
        }
    }

    /**
     * A credit note corrects an *invoice* (ADR-0031). An order that was
     * never invoiced — an `instant`-settled one paid at checkout — has no
     * document to correct, and cancelling it is money that moved without
     * paperwork, exactly as `RecordRefund` treats the same case.
     */
    private function creditNoteFor(Order $order): ?CreditNote
    {
        $invoiced = Invoice::withoutBrandScope()
            ->where('brand_id', $order->brand_id)
            ->where('order_id', $order->id)
            ->exists();

        return $invoiced
            ? $this->issueCreditNote->handle($order, IssueCreditNote::REASON_CANCELLATION)
            : null;
    }

    /**
     * The cancellation's mirror of `OrderLineReversed`, and the same
     * registration-kind filter: a `product` line is Commerce's own business,
     * and only a cart order settles more than one thing at once.
     */
    private function emitOrderLineCancelled(Order $order, ?string $cancelledByUserId, string $cancelledAt): void
    {
        if ($order->settles_type !== 'cart') {
            return;
        }

        $lines = OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('settles_kind', 'registration')
            ->whereNotNull('settles_ref')
            ->orderBy('position')
            ->get();

        foreach ($lines as $line) {
            if ($line->settles_kind === null || $line->settles_ref === null) {
                continue;
            }

            event(new OrderLineCancelled(
                orderId: $order->id,
                brandId: $order->brand_id,
                kind: $line->settles_kind,
                refId: $line->settles_ref,
                orderRequestKey: $order->request_key,
                reasonCode: self::REASON_REVERSAL_UNPAID,
                cancelledByUserId: $cancelledByUserId,
                cancelledAt: $cancelledAt,
            ));
        }
    }
}
