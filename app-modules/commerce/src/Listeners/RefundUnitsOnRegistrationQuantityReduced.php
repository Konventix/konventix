<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\RefundOrder;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Operations\AdminUrl;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Permissions\PermissionHolders;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use RuntimeException;
use Throwable;

/**
 * D-REG-15: a confirmed registration bought fewer units, so the money for
 * exactly those units goes back. Registration acts, Commerce reacts —
 * ADR-0004 forbids the other direction, and this is the same way
 * `RegistrationSettlementRequested` already runs.
 *
 * **Queued, with `$afterCommit`, and that is the ordering mechanism.**
 * `RegistrationQuantityReduced` is emitted inside
 * `ReduceRegistrationQuantity`'s transaction, and Admission's listener on
 * the same event is synchronous and throws when fewer than N admissions are
 * free. `afterCommit` hands this job's push to the transaction manager,
 * which drops it when that transaction rolls back — so a refused revocation
 * refunds nothing, and neither module has to know the other exists.
 * Handling is off-request for the ordinary reason too: an operator pressing
 * "reduce" must not wait on a PSP round trip.
 *
 * **What is refunded.** The order line that settles this registration —
 * `settles_kind: 'registration'` with the registration's own ULID as
 * `settles_ref`, which both a direct registration order and a cart order
 * write (`PlaceOrderFromSettlementRequest`, `PlaceOrderFromCart`). The
 * amount is `removedUnits × unit_amount_net` plus tax at the **line's own**
 * `tax_rate`, through `TaxCalculator`'s exact formula (scale 4, rounded
 * half-up to cents) rather than a re-resolution of the tax class: the rate
 * is a settlement-time snapshot (ADR-0017) and an order refunded next year
 * must be refunded at the rate it was charged at. That is also what makes
 * the credit note computable per unit at all — #7's general per-line
 * allocation stays open, because one line at one rate is the only case this
 * flow can produce.
 *
 * **No order, no refund.** A `free` or `external` registration never caused
 * a Commerce record (ADR-0026), and an unpaid one has nothing to send back.
 * Either way the reduction still happened — the seat and the admissions are
 * already gone — so this is a no-op, not a failure.
 *
 * **A refusal is an operator's problem, not a lost job.** By the time this
 * runs the reduction is committed and nobody is watching a request, so a
 * provider refusal or a check-in refusal becomes a `commerce.operations`
 * notice (ADR-0057 point 8) and the job is not retried into a second refund
 * attempt. The money is then sent back by hand in the provider's dashboard,
 * which `RecordPaymentReversal`'s `external_refund` path already records.
 */
final class RefundUnitsOnRegistrationQuantityReduced implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Load-bearing (see the docblock): the push waits for the outermost
     * transaction, so Admission's refusal — which rolls that transaction
     * back — takes this job with it.
     */
    public bool $afterCommit = true;

    private const string KIND_REGISTRATION = 'registration';

    /**
     * The refund's reason code on `OrderRefunded` and in the audit trail.
     * Deliberately not one of `RefundReason`'s cases: that enum closes the
     * *console's* vocabulary for a whole-order refund, and this refund has
     * no console form behind it.
     */
    public const string REASON_QUANTITY_REDUCED = 'registration-quantity-reduced';

    public function __construct(
        private readonly RefundOrder $refundOrder,
        private readonly AuditRecorder $auditRecorder,
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly AdminUrl $adminUrl,
    ) {}

    public function handle(RegistrationQuantityReduced $event): void
    {
        $line = $this->settlingLine($event);

        if ($line === null) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::withoutBrandScope()->find($line->order_id);

        if ($order === null || $order->status !== OrderStatus::Paid) {
            // Nothing settled yet, or the order is already gone. The
            // reduction stands regardless; there is simply no money to
            // return.
            return;
        }

        [$net, $tax, $gross] = $this->amountsFor($line, $event->removedUnits);

        if ($gross === '0.00') {
            return;
        }

        try {
            $this->refundOrder->handle(
                order: $order,
                reasonCode: self::REASON_QUANTITY_REDUCED,
                reasonNote: $event->reasonCode,
                amount: $gross,
                reductionKey: $event->reductionId,
                amountNet: $net,
                amountTax: $tax,
                registrationId: $event->registrationId,
                units: $event->removedUnits,
            );

            // The explicit half of the audit trail (Round 6, D-BATCH), the
            // same call `OrderAdminController::refund()` makes for the whole
            // refund: no event here is an `AuditableEvent`, and a partial
            // refund leaves the order `paid`, so without this row nothing in
            // the trail would say money left this order at all. `actorUserId`
            // is the operator who made the reduction, carried on the event —
            // the job itself has none.
            $this->auditRecorder->record(
                action: 'commerce.order.units-refunded',
                subjectId: $order->getKey(),
                brandId: $order->brand_id,
                context: [
                    'registration_id' => $event->registrationId,
                    'reduction_id' => $event->reductionId,
                    'units' => $event->removedUnits,
                    'amount' => $gross,
                    'amount_net' => $net,
                    'amount_tax' => $tax,
                    'currency' => $order->currency,
                ],
                actorUserId: $event->reducedByUserId,
            );
        } catch (Throwable $exception) {
            $this->notifyOperators($event, $order, $gross, $exception);

            Log::error('The refund for a reduced registration did not go through; the reduction stands (D-REG-15).', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'registration_id' => $event->registrationId,
                'reduction_id' => $event->reductionId,
                'amount' => $gross,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The one line that settles this registration. Both order shapes write
     * the pair, so no `settles_type` branch is needed here — and a cart
     * order that bought two different registrations has one line each.
     */
    private function settlingLine(RegistrationQuantityReduced $event): ?OrderLine
    {
        return OrderLine::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('settles_kind', self::KIND_REGISTRATION)
            ->where('settles_ref', $event->registrationId)
            ->orderBy('position')
            ->first();
    }

    /**
     * `units × unit_amount_net`, taxed at the line's snapshotted rate —
     * `TaxCalculator`'s own arithmetic, restated on one line because the
     * calculator resolves a *class* to a rate and this must not re-resolve
     * anything (ADR-0017: the order records what was charged).
     *
     * A zero-rate line (reverse charge, §19 UStG) comes out with zero tax
     * by construction, so the treatment needs no branch of its own.
     *
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string}
     */
    private function amountsFor(OrderLine $line, int $units): array
    {
        $capped = min($units, $line->quantity);

        $net = bcround(bcmul((string) $line->unit_amount_net, (string) $capped, TaxCalculator::SCALE), 2);
        $tax = bcround(
            bcdiv(bcmul($net, (string) $line->tax_rate, TaxCalculator::SCALE), '100', TaxCalculator::SCALE),
            2,
        );

        /** @var array{0: numeric-string, 1: numeric-string, 2: numeric-string} $amounts */
        $amounts = [$net, $tax, bcadd($net, $tax, 2)];

        return $amounts;
    }

    /**
     * ADR-0057 point 8: the operator's half. Same topic, same template and
     * the same absolute admin link every other `NotifyOperatorsOn*` uses.
     */
    private function notifyOperators(
        RegistrationQuantityReduced $event,
        Order $order,
        string $gross,
        Throwable $exception,
    ): void {
        $orderNumberShort = strtoupper(substr($order->id, -8));

        $title = "Erstattung offen: Bestellung {$orderNumberShort}";
        $detail = sprintf(
            '%s. %s %s für %d entfallene Einheit(en) konnten nicht erstattet werden: %s Die Reduzierung selbst steht; das Geld muss im Dashboard des Anbieters zurückgeschickt werden.',
            $title,
            $gross,
            $order->currency,
            $event->removedUnits,
            $exception->getMessage(),
        );

        $url = $this->adminUrl->to($event->brandId, 'admin.orders.show', ['order' => $order->id]);

        $holders = $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::ManageProducts->value);

        foreach ($holders as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::UnitRefundFailed,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    brandId: $event->brandId,
                    topicKey: 'commerce.operations',
                    // Money owed to a payer who has already lost their
                    // tickets is not digest material.
                    urgent: true,
                ));
            } catch (RuntimeException $sendFailure) {
                Log::warning('Unit-refund operations notice could not be sent.', [
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'reason' => $sendFailure->getMessage(),
                ]);
            }
        }
    }
}
