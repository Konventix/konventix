<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Mollie\MollieApi;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;
use Throwable;

/**
 * The scheduled provider reconciliation #71 asks for: "Ein regelmaessiger
 * Abgleich gegen den Provider meldet fehlende Vorgaenge."
 * `RefreshSettlementCapabilities` refreshes Stripe capabilities and nothing
 * else, so until this command a notification lost between the PSP and this
 * deployment was found by the payer, not by us.
 *
 * Two questions, both asked of the provider:
 *
 * 1. **Did a `pending` payment actually settle?** Older than fifteen
 *    minutes — below that the webhook is simply still in flight and asking
 *    would race it — and younger than seven days, because a checkout that
 *    old has been abandoned and its order cancelled by `ExpireCarts`; the
 *    money is not coming, and re-asking every hour forever would be a
 *    standing cost at the provider for no answer.
 * 2. **Did a settled Mollie/Unzer payment get charged back without us
 *    hearing?** Ninety days back, ADR-0057 point 7's last sentence: "a lost
 *    dispute notification is found by the sweep, not by the payer". Stripe
 *    is absent here on purpose — its disputes arrive as signed events on
 *    the webhook, and the `StripeApi` seam has no dispute listing behind
 *    it (ADR-0039 keeps the seam to what the adapter needs).
 *
 * The provider-state → transition mapping is deliberately re-implemented
 * here rather than shared with `Http\Controllers\Api\StripeWebhookController`,
 * `MollieWebhookController` and `UnzerWebhookController`: those handlers map
 * a *notification* (a signed event, a route parameter) and answer HTTP, this
 * one maps a *row we already hold* and answers a counter. The two must agree,
 * and the tests of both pin the same transitions; extracting a shared mapper
 * would have made a webhook's behaviour depend on a console sweep's shape.
 *
 * Everything here escapes BrandScope explicitly (ADR-0010): a console
 * command resolves no brand from a host.
 *
 * One payment's failure never aborts the sweep — a revoked API key or a
 * provider outage must not stall every other brand's reconciliation, the
 * same reasoning `RefreshSettlementCapabilities` states.
 */
final class ReconcilePayments extends Command
{
    /**
     * Below this the webhook is still plausibly in flight; asking would
     * race it and produce a "correction" that was never wrong.
     */
    public const int PENDING_MIN_AGE_MINUTES = 15;

    /**
     * Above this the checkout is abandoned rather than unreported.
     */
    public const int PENDING_MAX_AGE_DAYS = 7;

    /**
     * How far back a settled payment is re-read for chargebacks. Longer
     * than any provider's own chargeback deadline we know of, so a late
     * dispute is still found.
     */
    public const int SETTLED_LOOKBACK_DAYS = 90;

    /**
     * `Mollie\Api\Types\RefundStatus::REFUNDED`, the same constant
     * `MollieWebhookController` gates on: only money that reached the payer
     * is a reversal.
     */
    private const string MOLLIE_REFUND_TRANSFERRED = 'refunded';

    /**
     * Stripe PaymentIntent statuses that mean the attempt will not settle:
     * `requires_payment_method` is where a refused asynchronous payment
     * lands (the intent asks for another method), `canceled` is the
     * terminal give-up. `processing` and `requires_action` are deliberately
     * absent — money still in flight must never be failed by a sweep.
     *
     * @var list<string>
     */
    private const array REFUSED_INTENT_STATUSES = ['requires_payment_method', 'canceled'];

    protected $signature = 'commerce:reconcile-payments {--brand= : Restrict the sweep to one brand}';

    protected $description = 'Ask each PSP what really happened to payments whose notification may have been lost (Commerce, #71).';

    private int $checked = 0;

    private int $corrected = 0;

    private int $reversalsFound = 0;

    /**
     * Payments this run just settled. A payment corrected in the pending
     * pass matches the settled pass's own query moments later — the cursor
     * is lazy, so it reads the row this run wrote — and asking the provider
     * about a payment we settled seconds ago is exactly the per-request
     * provider call #71 complains about. It cannot have a chargeback yet;
     * the next hourly run reads it.
     *
     * @var list<string>
     */
    private array $justCorrected = [];

    public function handle(
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
        RecordPaymentReversal $recordReversal,
    ): int {
        foreach ($this->pendingPayments()->cursor() as $payment) {
            $this->checked++;

            try {
                $this->reconcilePending($payment, $recordSuccess, $recordFailure);
            } catch (Throwable $e) {
                $this->reportFailure($payment, $e);
            }
        }

        foreach ($this->settledPayments()->cursor() as $payment) {
            if (in_array($payment->id, $this->justCorrected, true)) {
                continue;
            }

            $this->checked++;

            try {
                $this->reconcileSettled($payment, $recordReversal);
            } catch (Throwable $e) {
                $this->reportFailure($payment, $e);
            }
        }

        $this->components->info(sprintf(
            'Reconciliation complete: %d payment(s) checked, %d corrected, %d reversal(s) found.',
            $this->checked,
            $this->corrected,
            $this->reversalsFound,
        ));

        return self::SUCCESS;
    }

    /**
     * Unzer is absent: a pending Unzer payment's `external_id` is the
     * Paypage id, and `UnzerApi::fetchPayment()` takes a *payment* id,
     * which only exists once the payment was charged and is stored on
     * `provider_transaction_id` at settlement. Correlating one before that
     * needs Unzer's own order-id lookup, which the seam does not carry.
     *
     * @return Builder<Payment>
     */
    private function pendingPayments(): Builder
    {
        $query = Payment::withoutBrandScope()
            ->where('status', PaymentStatus::Pending)
            ->whereIn('provider', [SettlementAccount::PROVIDER_STRIPE, SettlementAccount::PROVIDER_MOLLIE])
            ->whereNotNull('external_id')
            ->where('created_at', '<=', now()->subMinutes(self::PENDING_MIN_AGE_MINUTES))
            ->where('created_at', '>=', now()->subDays(self::PENDING_MAX_AGE_DAYS));

        return $this->restrictToBrand($query);
    }

    /**
     * `Reversed` beside `Succeeded`, for the reason
     * `MollieWebhookController::isLive()` gives: a payment that was already
     * charged back once can be charged back again — a second chargeback,
     * or a dashboard refund of what was left — and a sweep that stopped
     * looking after the first would drop exactly the notification it exists
     * to find.
     *
     * @return Builder<Payment>
     */
    private function settledPayments(): Builder
    {
        $query = Payment::withoutBrandScope()
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Reversed])
            ->whereIn('provider', [SettlementAccount::PROVIDER_MOLLIE, SettlementAccount::PROVIDER_UNZER])
            ->where('received_at', '>=', now()->subDays(self::SETTLED_LOOKBACK_DAYS));

        return $this->restrictToBrand($query);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private function restrictToBrand(Builder $query): Builder
    {
        $brandId = $this->option('brand');

        return is_string($brandId) && $brandId !== '' ? $query->where('brand_id', $brandId) : $query;
    }

    private function reconcilePending(
        Payment $payment,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
    ): void {
        match ($payment->provider) {
            SettlementAccount::PROVIDER_STRIPE => $this->reconcileStripePending($payment, $recordSuccess, $recordFailure),
            SettlementAccount::PROVIDER_MOLLIE => $this->reconcileMolliePending($payment, $recordSuccess, $recordFailure),
            default => null,
        };
    }

    /**
     * Mirrors `StripeWebhookController::settled()`/`failed()`: the payment
     * intent is recorded even while unpaid (a later refund needs it), the
     * session settles on any `payment_status` but `unpaid` — with
     * delayed-notification methods a session completes while still unpaid —
     * and an `expired` session is the same terminal failure
     * `checkout.session.expired` reports.
     */
    private function reconcileStripePending(
        Payment $payment,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
    ): void {
        $account = $this->accountFor($payment);
        $stripeAccountId = $account?->provider_account_id;

        if ($payment->external_id === null || ! is_string($stripeAccountId) || $stripeAccountId === '') {
            return;
        }

        $session = app(StripeApi::class)->fetchCheckoutSession($payment->external_id, $stripeAccountId);

        if ($session['payment_intent'] !== null) {
            $payment->forceFill(['provider_transaction_id' => $session['payment_intent']])->save();
        }

        if ($session['payment_status'] !== 'unpaid') {
            $this->applyTransition(
                $payment,
                fn () => $recordSuccess->handle($payment),
                'succeeded',
                $session['payment_status'],
            );

            return;
        }

        if ($session['status'] === 'expired') {
            $this->applyTransition(
                $payment,
                fn () => $recordFailure->handle($payment, 'checkout_expired', expired: true),
                'expired',
                $session['status'],
            );

            return;
        }

        // The lost `checkout.session.async_payment_failed`: with a
        // delayed-notification method the session completes while still
        // unpaid and never turns `expired`, so only the intent says the
        // attempt was refused. `processing` is money still in flight and
        // must not be failed; `requires_payment_method` and `canceled` are
        // the two terminal answers the webhook's own arm reacts to.
        if ($session['status'] === 'complete' && in_array($session['payment_intent_status'], self::REFUSED_INTENT_STATUSES, true)) {
            $this->applyTransition(
                $payment,
                fn () => $recordFailure->handle($payment, 'async_payment_failed'),
                'failed',
                (string) $session['payment_intent_status'],
            );
        }
    }

    /**
     * Applies one transition and reports it **only if it actually moved**.
     *
     * `RecordPaymentSuccess`/`RecordPaymentFailure` are no-ops on a row that
     * already left `pending`, so a webhook that settled the payment while
     * this sweep was mid-question makes the call do nothing — and counting
     * it as "corrected" would raise a notice claiming a notification never
     * arrived when it had just arrived. The status before and after is the
     * only honest test of that.
     */
    private function applyTransition(Payment $payment, callable $apply, string $newState, string $providerState): void
    {
        // Re-read first: the cursor loaded this row before the provider was
        // asked, and asking took a network round trip. The status the row
        // has *now* is the only baseline a comparison may use.
        $payment->refresh();

        $before = $payment->status;

        $apply();

        $payment->refresh();

        if ($payment->status === $before) {
            return;
        }

        $this->correction($payment, $newState, $providerState);
    }

    /**
     * Mirrors `MollieWebhookController`'s settlement `match` exactly.
     */
    private function reconcileMolliePending(
        Payment $payment,
        RecordPaymentSuccess $recordSuccess,
        RecordPaymentFailure $recordFailure,
    ): void {
        $apiKey = $this->mollieKey($payment);

        if ($payment->external_id === null || $apiKey === null) {
            return;
        }

        $snapshot = app(MollieApi::class)->fetchPayment($payment->external_id, $apiKey);

        match ($snapshot->status) {
            'paid' => $this->applyMollieSuccess($payment, $recordSuccess),
            'failed' => $this->applyMollieFailure($payment, $recordFailure, 'failed'),
            'canceled' => $this->applyMollieFailure($payment, $recordFailure, 'canceled'),
            'expired' => $this->applyMollieFailure($payment, $recordFailure, 'expired', expired: true),
            // open / pending / authorized: nothing decided yet.
            default => null,
        };
    }

    private function applyMollieSuccess(Payment $payment, RecordPaymentSuccess $recordSuccess): void
    {
        $this->applyTransition($payment, fn () => $recordSuccess->handle($payment), 'succeeded', 'paid');
    }

    private function applyMollieFailure(
        Payment $payment,
        RecordPaymentFailure $recordFailure,
        string $reasonCode,
        bool $expired = false,
    ): void {
        $this->applyTransition(
            $payment,
            fn () => $recordFailure->handle($payment, $reasonCode, expired: $expired),
            $expired ? 'expired' : 'failed',
            $reasonCode,
        );
    }

    private function reconcileSettled(Payment $payment, RecordPaymentReversal $recordReversal): void
    {
        match ($payment->provider) {
            SettlementAccount::PROVIDER_MOLLIE => $this->reconcileMollieSettled($payment, $recordReversal),
            SettlementAccount::PROVIDER_UNZER => $this->reconcileUnzerSettled($payment, $recordReversal),
            default => null,
        };
    }

    /**
     * The same fetch-back `MollieWebhookController::recordReversals()` does,
     * in the same order and with the same guards, asked on a schedule
     * instead of on a notification: every chargeback is a `return` keyed by
     * its own `chb_…` id, and a transferred refund found beside an order
     * that was still `paid` when the scan began is the operator refunding
     * in Mollie's dashboard. `RecordPaymentReversal` is idempotent under the unique
     * `(provider, provider_reference)` key, so re-reading the same
     * chargeback every hour records it once.
     */
    private function reconcileMollieSettled(Payment $payment, RecordPaymentReversal $recordReversal): void
    {
        $apiKey = $this->mollieKey($payment);

        if ($payment->external_id === null || $apiKey === null) {
            return;
        }

        $snapshot = app(MollieApi::class)->fetchPayment($payment->external_id, $apiKey);

        foreach ($snapshot->chargebacks as $chargeback) {
            $this->recordReversal(
                $payment,
                $recordReversal,
                ReversalKind::Return,
                $chargeback['amount'],
                $payment->currency,
                $chargeback['id'],
                $chargeback['reason'],
                $this->occurredAt($chargeback['createdAt']),
            );

            $payment->refresh();
        }

        // Asked **once**, above the loop, exactly as
        // `MollieWebhookController::recordReversals()` asks it: recording
        // the first external refund moves the order to `refunded`, so
        // re-asking per refund would drop every further refund of the same
        // snapshot — and the next run would drop them again, leaving a
        // two-part dashboard refund half recorded forever.
        if (! $this->orderStillPaid($payment)) {
            return;
        }

        foreach ($snapshot->refunds as $refund) {
            // Only money that actually reached the payer is a reversal.
            // `queued`, `pending` and `processing` have not moved yet and
            // may still be cancelled; `failed` and `canceled` never will.
            if ($refund['status'] !== self::MOLLIE_REFUND_TRANSFERRED) {
                continue;
            }

            $this->recordReversal(
                $payment,
                $recordReversal,
                ReversalKind::ExternalRefund,
                $refund['amount'],
                $payment->currency,
                $refund['id'],
                $refund['reason'],
                $this->occurredAt($refund['createdAt']),
            );

            $payment->refresh();
        }
    }

    /**
     * The same shape `UnzerWebhookController::recordChargebacks()` records,
     * correlated through `provider_transaction_id` — the Unzer payment id
     * stamped at settlement. A settled payment always has one; a row
     * without it predates the chargeback path and has nothing to fetch.
     */
    private function reconcileUnzerSettled(Payment $payment, RecordPaymentReversal $recordReversal): void
    {
        $account = $this->accountFor($payment);
        $privateKey = $account?->credentials['private_key'] ?? null;

        if ($payment->provider_transaction_id === null || ! is_string($privateKey) || $privateKey === '') {
            return;
        }

        $snapshot = app(UnzerApi::class)->fetchPayment($payment->provider_transaction_id, $privateKey);

        if ($snapshot->state !== 'chargeback') {
            return;
        }

        foreach ($snapshot->chargebacks as $chargeback) {
            $this->recordReversal(
                $payment,
                $recordReversal,
                ReversalKind::Return,
                $chargeback['amount'],
                $chargeback['currency'] !== '' ? $chargeback['currency'] : $payment->currency,
                $chargeback['id'],
                // Unzer reports no bank reason code on the chargeback
                // transaction, exactly as in the webhook path.
                null,
                $this->occurredAt($chargeback['date']),
            );

            $payment->refresh();
        }
    }

    private function recordReversal(
        Payment $payment,
        RecordPaymentReversal $recordReversal,
        ReversalKind $kind,
        string $amount,
        string $currency,
        string $providerReference,
        ?string $reasonCode,
        Carbon $occurredAt,
    ): void {
        $reversal = $recordReversal->handle(
            payment: $payment,
            kind: $kind,
            status: ReversalStatus::Final,
            amount: $amount,
            currency: $currency,
            providerReference: $providerReference,
            reasonCode: $reasonCode,
            feeAmount: null,
            occurredAt: $occurredAt,
        );

        // A replay converges on the existing row (ADR-0057 point 1), so only
        // a row that did not exist before is a notification we never got.
        if (! $reversal->wasRecentlyCreated) {
            return;
        }

        $this->reversalsFound++;

        Log::warning('Payment reversal found by reconciliation; the provider notification never arrived (#71).', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'brand_id' => $payment->brand_id,
            'provider' => $payment->provider,
            'reversal_id' => $reversal->id,
            'provider_reference' => $providerReference,
            'kind' => $kind->value,
        ]);

        $this->notifyOperators(
            $payment,
            sprintf('Rückbuchung durch Abgleich gefunden: Bestellung %s', $this->orderNumberShort($payment)),
            sprintf(
                'Der Provider meldet eine Rückbuchung (%s), zu der keine Benachrichtigung eingegangen ist. Der regelmäßige Abgleich hat sie nachgetragen.',
                $providerReference,
            ),
        );
    }

    /**
     * A payment corrected here is a payment whose webhook never arrived —
     * the ids are what makes the log line actionable rather than an alarm.
     */
    private function correction(Payment $payment, string $newState, string $providerState): void
    {
        $this->corrected++;
        $this->justCorrected[] = $payment->id;

        Log::warning('Payment state corrected; found by reconciliation, the provider notification never arrived (#71).', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'brand_id' => $payment->brand_id,
            'provider' => $payment->provider,
            'external_id' => $payment->external_id,
            'corrected_to' => $newState,
            'provider_state' => $providerState,
        ]);

        $this->notifyOperators(
            $payment,
            sprintf('Zahlung durch Abgleich korrigiert: Bestellung %s', $this->orderNumberShort($payment)),
            sprintf(
                'Der Provider meldet den Zustand "%s"; die Benachrichtigung ist nie eingegangen. Der regelmäßige Abgleich hat die Zahlung auf "%s" gesetzt.',
                $providerState,
                $newState,
            ),
        );
    }

    /**
     * ADR-0057 point 8's operator notice, composed the way
     * `NotifyOperatorsOnPaymentFailed` composes its own — same topic, same
     * template, same absolute admin link. Written here rather than in a
     * listener because a sweep is not an event: the fact worth telling an
     * operator is that the *notification path* is lossy, which no domain
     * event carries.
     */
    private function notifyOperators(Payment $payment, string $title, string $detail): void
    {
        $sendMessage = app(SendMessage::class);
        $holders = app(PermissionHolders::class)
            ->userIdsWithPermission($payment->brand_id, Permission::ManageProducts->value);

        $url = $this->adminUrl($payment->brand_id, 'admin.orders.show', ['order' => $payment->order_id]);

        foreach ($holders as $userId) {
            try {
                $sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::PaymentReconciled,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    brandId: $payment->brand_id,
                    topicKey: 'commerce.operations',
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Reconciliation operations notice could not be sent.', [
                    'user_id' => $userId,
                    'payment_id' => $payment->id,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function adminUrl(string $brandId, string $routeName, array $parameters): string
    {
        $base = app(OperatorSettings::class)->adminBaseUrlForBrand($brandId);
        $path = route($routeName, $parameters, absolute: false);

        return $base === null ? $path : rtrim($base, '/').$path;
    }

    private function orderNumberShort(Payment $payment): string
    {
        return strtoupper(substr($payment->order_id, -8));
    }

    private function accountFor(Payment $payment): ?SettlementAccount
    {
        return $payment->settlement_account_id === null
            ? null
            : SettlementAccount::withoutBrandScope()->find($payment->settlement_account_id);
    }

    private function mollieKey(Payment $payment): ?string
    {
        $apiKey = $this->accountFor($payment)?->credentials['api_key'] ?? null;

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    private function orderStillPaid(Payment $payment): bool
    {
        $order = Order::withoutBrandScope()->find($payment->order_id);

        return $order !== null && $order->status === OrderStatus::Paid;
    }

    /**
     * An unparsable or missing provider date must not lose the reversal, so
     * the moment we read it is the fallback — the same choice both
     * fetch-back controllers make.
     */
    private function occurredAt(?string $value): Carbon
    {
        if ($value === null || $value === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return Carbon::now();
        }
    }

    private function reportFailure(Payment $payment, Throwable $e): void
    {
        Log::error(sprintf(
            'Reconciliation failed for payment [%s]: %s',
            $payment->id,
            $e->getMessage(),
        ), ['exception' => $e, 'brand_id' => $payment->brand_id, 'provider' => $payment->provider]);
    }
}
