<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions\Concerns;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;

/**
 * The #231 question — "did the payer pay this at the provider after all?" —
 * and what each answer means for an order a sweep is about to cancel.
 *
 * Shared by `ExpireCarts` and `ExpireRegistrationOrders` rather than copied
 * into both (fix round 2, review NEW-5): the two sweeps release different
 * things — stock and cart holds on one side, registration quota on the other
 * — but the decision about the *money* is the same one, and the copy that
 * goes stale would be the expensive one.
 *
 * The using class must have a `HostedPaymentProbe $probe` and a
 * `RecordPaymentSuccess $recordSuccess`.
 */
trait AsksThePspBeforeCancelling
{
    /**
     * Orders whose release valve fired this run, and what the provider had
     * said about them — kept until the caller has actually cancelled them
     * (fix round 3, review F-4).
     *
     * The decision happens before the sweep's transaction, and that
     * transaction re-reads the order under a lock and gives up if a late
     * webhook settled it in between — the very case the probe exists for. An
     * operator must not be told an order was cancelled that then was not.
     *
     * @var array<string, array{payment: Payment, state: HostedPaymentState}>
     */
    private array $valveFired = [];

    /**
     * Whether the sweep may go on and cancel `$order`.
     *
     * `false` means "leave this order alone this run", for reasons that are
     * deliberately not interchangeable:
     *
     * - `Paid` — the money is at the provider and has just been booked
     *   through the ordinary success path. The lost webhook's work, done
     *   late.
     * - `Unprobed` — this run spent its probe budget before reaching the
     *   order. Nothing is known and nothing is decided; the next run asks
     *   (review NEW-1).
     * - `InFlight` / `Unreachable` — the provider says the money is
     *   travelling, or could not be asked. Held until its own ceiling
     *   passes: a fortnight for money in flight, a day for a silent
     *   provider (review NEW-2). Past that the order is cancelled anyway and
     *   an operator is told, because a seat cannot be held for ever — and
     *   money that did move comes back as unapplied money the console can
     *   refund (ADR-0057 point 9, #6).
     *
     * `NotPaid` and `Unanswerable` return `true`: nothing is coming, or the
     * provider has no way of answering about this payment at all.
     *
     * `$sweep` names the caller in the log line and the operator notice;
     * only the two sweeps use this.
     */
    private function providerSaysThisOrderMayBeCancelled(Order $order, string $sweep): bool
    {
        [$state, $payment] = $this->probe->ask($order);

        if ($payment === null) {
            return true;
        }

        if ($state === HostedPaymentState::Paid) {
            Log::warning(sprintf('%s found a hosted payment the provider calls paid; booking it instead of cancelling (#231).', $sweep), [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $payment->external_id,
            ]);

            $this->recordSuccess->handle($payment);

            return false;
        }

        if ($state === HostedPaymentState::Unprobed) {
            // Silent on purpose: an order the run did not reach is not an
            // incident, and one log line per skipped order during an outage
            // is noise that hides the ones that matter.
            return false;
        }

        if ($state !== HostedPaymentState::InFlight && $state !== HostedPaymentState::Unreachable) {
            return true;
        }

        if (! $this->probe->holdExpired($order, $state)) {
            Log::warning(sprintf('%s left an order standing: %s (#231).', $sweep, $state === HostedPaymentState::InFlight
                ? 'the provider says its hosted payment is still on its way'
                : 'the provider could not say whether its hosted payment was paid'), [
                    'order_id' => $order->id,
                    'brand_id' => $order->brand_id,
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'external_id' => $payment->external_id,
                    'state' => $state->value,
                ]);

            return false;
        }

        // Recorded, not sent: `reportCancellation()` sends it once the
        // caller's transaction has committed the cancellation (F-4).
        $this->valveFired[$order->id] = ['payment' => $payment, 'state' => $state];

        return true;
    }

    /**
     * The release valve firing is the one thing in this sweep an operator
     * has to see (#231's fourth criterion): an order was called off without
     * the provider ever confirming what happened to its money. The log line
     * carries the ids, the notice carries the sentence — composed the way
     * `ReconcilePayments` composes its own, same topic, same template, same
     * absolute admin link.
     *
     * Called by the sweep **after** its transaction committed the
     * cancellation, and a no-op for every order whose valve did not fire, so
     * a cancellation that lost the race to a late webhook is never announced
     * (fix round 3, review F-4).
     */
    private function reportCancellation(Order $order, string $sweep): void
    {
        $fired = $this->valveFired[$order->id] ?? null;

        if ($fired === null) {
            return;
        }

        unset($this->valveFired[$order->id]);

        $payment = $fired['payment'];
        $state = $fired['state'];

        Log::warning(sprintf('%s cancelled an order the provider never settled; check it for unapplied money (#231).', $sweep), [
            'order_id' => $order->id,
            'brand_id' => $order->brand_id,
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'external_id' => $payment->external_id,
            'state' => $state->value,
        ]);

        $detail = $state === HostedPaymentState::InFlight
            ? sprintf(
                'Der Anbieter (%s) meldete die Zahlung bis zuletzt als unterwegs. Die Frist dafür ist abgelaufen, die Bestellung wurde storniert. Sollte das Geld doch eingegangen sein, findet der Abgleich es als nicht zugeordnete Zahlung – dann steht es auf der Bestellung und kann von dort erstattet werden.',
                $payment->provider,
            )
            : sprintf(
                'Der Anbieter (%s) war über die gesamte Frist nicht erreichbar oder konnte nichts zur Zahlung sagen. Die Bestellung wurde storniert. Sollte das Geld doch eingegangen sein, findet der Abgleich es als nicht zugeordnete Zahlung – dann steht es auf der Bestellung und kann von dort erstattet werden.',
                $payment->provider,
            );

        $this->notifyOperators(
            $order,
            sprintf('Bestellung %s ohne Antwort des Anbieters storniert', strtoupper(substr($order->id, -8))),
            $detail,
        );
    }

    /**
     * ADR-0057 point 8's operator notice, sent the way
     * `ReconcilePayments::notifyOperators()` sends it — a sweep is not an
     * event, so this is written where the fact is known rather than in a
     * listener. One failed recipient never stops the sweep.
     */
    private function notifyOperators(Order $order, string $title, string $detail): void
    {
        $sendMessage = app(SendMessage::class);
        $holders = app(PermissionHolders::class)
            ->userIdsWithPermission($order->brand_id, Permission::ManageProducts->value);

        $base = app(OperatorSettings::class)->adminBaseUrlForBrand($order->brand_id);
        $path = route('admin.orders.show', ['order' => $order->id], absolute: false);
        $url = $base === null ? $path : rtrim($base, '/').$path;

        foreach ($holders as $userId) {
            try {
                $sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::SweepCancelledUnsettled,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    brandId: $order->brand_id,
                    topicKey: 'commerce.operations',
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Sweep operations notice could not be sent.', [
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
