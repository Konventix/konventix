<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\MinorUnits;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Scopes\BrandScope;
use LogicException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Stripe, as ADR-0058 rewrites ADR-0039 point 5: every charge is made on
 * **this instance's own** Stripe account — the operator running it is the
 * merchant of record, keeps the full dashboard and pays Stripe's fees —
 * with hosted Checkout Sessions only and settlement truth exclusively from
 * the signed webhook. No connected account, no `Stripe-Account` header, and
 * no `application_fee_amount`, ever: ADR-0007 forbids deducting platform
 * monetization from ticket revenue.
 *
 * One account, many brands: the split is metadata (ADR-0058 point 4).
 * `konventix_brand_id`, `konventix_brand_name` and `konventix_operator_id`
 * ride on the session *and* on the PaymentIntent, so they survive onto the
 * charge, the balance transaction and every Stripe export; `description`
 * names the brand in plain words for the rows that show no metadata, and
 * the brand's `statement_descriptor_suffix` names it on the payer's card
 * statement.
 *
 * `payment_method_types` is deliberately absent from the session params:
 * omitting it enables Stripe's dynamic payment methods, configured from the
 * operator's own dashboard.
 */
final class StripeSettlementAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    /**
     * Tags sessions in the Stripe dashboard; the random-looking suffix is
     * required by the API convention and fixed per integration, not per
     * session.
     */
    private const string INTEGRATION_IDENTIFIER = 'konventix-instant-checkout-qvxbrmda';

    public function __construct(
        private readonly StripeApi $stripe,
    ) {}

    public function startCheckout(
        SettlementAccount $account,
        Order $order,
        string $returnUrl,
    ): CheckoutRedirect {
        if ($account->provider !== SettlementAccount::PROVIDER_STRIPE) {
            throw new RuntimeException(sprintf(
                'Settlement account [%s] is not a Stripe account.',
                $account->id,
            ));
        }

        $payment = new Payment([
            'order_id' => $order->id,
            'settlement_account_id' => $account->id,
            'provider' => SettlementAccount::PROVIDER_STRIPE,
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_gross,
            'currency' => $order->currency,
        ]);
        // brand_id from the order, not ambient context (ADR-0010): checkout
        // may start from a queue or the public brand host alike.
        $payment->forceFill(['brand_id' => $order->brand_id])->save();

        // Read through Platform's own Brand model — the cross-module
        // allow-list covers it (ADR-0004) — and not brand-scoped itself, so
        // this answers on the queue and the public host alike. `orders.brand_id`
        // is a constrained foreign key, so the row is there or the order
        // is not.
        /** @var Brand $brand */
        $brand = Brand::query()->findOrFail($order->brand_id);
        $brandName = $brand->name;

        // Stripe stores metadata as strings; a null would travel as an
        // empty value rather than as "unknown", so it is spelled out.
        $metadata = [
            'konventix_payment_id' => $payment->id,
            'konventix_order_id' => $order->id,
            'konventix_brand_id' => $order->brand_id,
            'konventix_brand_name' => $brandName,
            // `brands.operator_id` is NOT NULL, so this is a cast and not a
            // fallback: an empty-string operator id riding into Stripe's
            // metadata would be worse than the exception a missing column
            // would raise.
            'konventix_operator_id' => (string) $brand->operator_id,
        ];

        $intentData = [
            'metadata' => $metadata,
            // What a Stripe row without metadata shows — a dashboard
            // search, a dispute mail, a CSV export.
            'description' => sprintf('%s — order %s', $brandName, $order->id),
        ];

        $suffix = $account->statement_descriptor_suffix;

        if (is_string($suffix) && trim($suffix) !== '') {
            // Appended to the account's own prefix by Stripe, which caps
            // the two together at 22 characters and refuses the charge if
            // they overflow. The console validates the suffix against the
            // worst legal prefix at save time (ADR-0058 point 3), and the
            // retry below covers the case where that guess was still wrong.
            $intentData['statement_descriptor_suffix'] = trim($suffix);
        }

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'line_items' => $this->lineItems($order),
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => $metadata,
            'payment_intent_data' => $intentData,
            'integration_identifier' => self::INTEGRATION_IDENTIFIER,
        ];

        $session = $this->createSessionWithoutLettingTheDescriptorCostThePayment($params, $order);

        $payment->forceFill(['external_id' => $session->id])->save();

        return new CheckoutRedirect(paymentId: $payment->id, url: $session->url);
    }

    /**
     * The suffix is cosmetic; the payment is not.
     *
     * Stripe caps the combined statement descriptor — the account's own
     * prefix plus this suffix — at 22 characters, and the console can only
     * guess at the prefix's length: `fetchAccount()` went with Connect
     * (ADR-0058), so nothing here can read it. The save-time rule is
     * therefore conservative rather than exact, and this is what happens when
     * it guesses wrong anyway: the descriptor is dropped and the session
     * retried once, so the payer reaches a payment page and the charge shows
     * the account's default descriptor instead of the brand's. A misconfigured
     * cosmetic field must not be able to break a brand's whole money path.
     *
     * Anything else Stripe refuses is a real fault and travels on unchanged.
     *
     * @param  array<string, mixed>  $params
     */
    private function createSessionWithoutLettingTheDescriptorCostThePayment(array $params, Order $order): StripeCheckoutSession
    {
        try {
            return $this->stripe->createCheckoutSession($params);
        } catch (InvalidRequestException $exception) {
            $intentData = $params['payment_intent_data'] ?? [];

            if (! is_array($intentData) || ! array_key_exists('statement_descriptor_suffix', $intentData)
                || ! self::isStatementDescriptorRejection($exception)) {
                throw $exception;
            }

            Log::warning('Stripe refused the statement descriptor suffix; retrying the checkout session without it.', [
                'order_id' => $order->id,
                'brand_id' => $order->brand_id,
                'statement_descriptor_suffix' => $intentData['statement_descriptor_suffix'],
                'stripe_message' => $exception->getMessage(),
            ]);

            unset($intentData['statement_descriptor_suffix']);
            $params['payment_intent_data'] = $intentData;

            return $this->stripe->createCheckoutSession($params);
        }
    }

    private static function isStatementDescriptorRejection(InvalidRequestException $exception): bool
    {
        return str_contains((string) $exception->getStripeParam(), 'statement_descriptor')
            || str_contains($exception->getMessage(), 'statement descriptor')
            || str_contains($exception->getMessage(), 'statement_descriptor');
    }

    /**
     * Stripe PaymentIntent statuses that mean the attempt will not settle:
     * `requires_payment_method` is where a refused asynchronous payment
     * lands (the intent asks for another method), `canceled` is the
     * terminal give-up. `processing` and `requires_action` are deliberately
     * absent — money still in flight must never be called off.
     *
     * Shared with `ReconcilePayments`, which asks the same question of the
     * same seam on a schedule: the two must not be able to drift.
     *
     * @var list<string>
     */
    public const array REFUSED_INTENT_STATUSES = ['requires_payment_method', 'canceled'];

    /**
     * #231: the sweep asks before it cancels. `payment_status` is the same
     * field `StripeWebhookController::settled()` gates on — with a
     * delayed-notification method (SEPA debit, bank transfer) a session
     * reaches `complete` while still `unpaid`, so the session's own `status`
     * cannot answer this on its own.
     *
     * **`unpaid` is therefore not "not paid"** (fix round 1, review finding
     * 1). A completed session whose intent is still `processing` is money on
     * its way: calling that `NotPaid` would have the sweep cancel the order
     * and mail the buyer mid-transfer, which is the very class of failure
     * #231 was filed for. Only an intent that has actually given up —
     * `REFUSED_INTENT_STATUSES`, the same list `ReconcilePayments` fails a
     * pending payment on — makes it `NotPaid`, and so does a session that
     * was never completed at all. Mollie's sibling draws the same line for
     * `pending`/`authorized`.
     *
     * Any failure at all is `Unreachable`, never `NotPaid`: an outage, a
     * rejected key or a session id Stripe does not recognise all mean "we
     * do not know", and not knowing must not cancel an order somebody paid
     * for. Money the intent says is still travelling is `InFlight`, which
     * the sweep holds far longer than a provider that simply went quiet
     * (fix round 2, review NEW-2).
     */
    public function hostedPaymentState(Payment $payment): HostedPaymentState
    {
        if ($payment->external_id === null || $payment->external_id === '') {
            return HostedPaymentState::Unanswerable;
        }

        try {
            $session = $this->stripe->fetchCheckoutSession($payment->external_id);
        } catch (Throwable $e) {
            Log::warning('Stripe could not be asked whether a pending checkout session was paid (#231).', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'external_id' => $payment->external_id,
                'reason' => $e->getMessage(),
            ]);

            return HostedPaymentState::Unreachable;
        }

        if ($session['payment_status'] !== 'unpaid') {
            return HostedPaymentState::Paid;
        }

        // Completed and unpaid: a delayed-notification method. The intent is
        // the only thing that can say whether it is still coming. A session
        // Stripe declined to expand an intent for says nothing either, which
        // is also "we do not know".
        if ($session['status'] === 'complete'
            && ! in_array($session['payment_intent_status'], self::REFUSED_INTENT_STATUSES, true)) {
            return HostedPaymentState::InFlight;
        }

        return HostedPaymentState::NotPaid;
    }

    public function refund(
        string $accountId,
        Order $order,
        string $amount,
        string $currency,
    ): string {
        $payment = Payment::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('provider', SettlementAccount::PROVIDER_STRIPE)
            ->where('status', PaymentStatus::Succeeded)
            ->latest('received_at')
            ->first();

        if ($payment?->provider_transaction_id === null) {
            throw new RuntimeException(sprintf(
                'Order [%s] has no refundable Stripe payment on account [%s].',
                $order->id,
                $accountId,
            ));
        }

        // Direct on this instance's own account (ADR-0058): the intent
        // recorded at settlement is the whole address of the money.
        //
        // The SDK's exception is translated here rather than let out (#236
        // fix round 1): `ApiErrorException` extends `Exception`, so it used
        // to reach the console as a 500, and the console must not have to
        // know a provider's SDK. `getError()?->message` is Stripe's own
        // sentence about this request; `getMessage()` prefixes it with the
        // HTTP status, which is noise in a form error.
        try {
            // The `re_…` Stripe assigns, carried back so the reversal row can
            // record it (#247): without it no path led from the order to the
            // money's own identifier at the provider.
            return $this->stripe->createRefund([
                'payment_intent' => $payment->provider_transaction_id,
                'amount' => MinorUnits::fromDecimal($amount, $currency),
            ]);
        } catch (AuthenticationException $e) {
            throw ProviderRefusedRefundException::misconfigured(
                SettlementAccount::PROVIDER_STRIPE,
                self::stripeMessage($e),
                $e,
            );
        } catch (ApiErrorException $e) {
            throw ProviderRefusedRefundException::refused(
                SettlementAccount::PROVIDER_STRIPE,
                self::stripeMessage($e),
                $e,
            );
        }
    }

    /**
     * Stripe's own sentence about the request. `getMessage()` prefixes it
     * with "Error sending request to Stripe: (Status 400)", which is noise
     * in a form error; the error object carries the sentence alone — but it
     * is absent for a transport failure, which has no API error body.
     */
    private static function stripeMessage(ApiErrorException $e): string
    {
        $message = $e->getError()?->message;

        return $message === null || $message === '' ? $e->getMessage() : $message;
    }

    /**
     * Never called for Stripe: settlement truth comes exclusively from the
     * signed webhook (ADR-0039), which runs `RecordPaymentSuccess` — not
     * through this contract method. Manual booking against a
     * Stripe-enabled brand goes through `ManualSettlementAdapter`
     * explicitly.
     */
    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        throw new LogicException(
            'Stripe settlement is webhook-driven (ADR-0039); nothing may mark a Stripe order paid by hand through this adapter.',
        );
    }

    public function defaultCheckoutLabel(): string
    {
        return __('Card, Apple Pay, Google Pay');
    }

    /**
     * One Checkout line per order line, at the snapshotted gross amount
     * (ADR-0017/ADR-0030): tax is already computed and rounded per line by
     * the domain, so the sum of Stripe's lines equals the invoice total to
     * the cent — Stripe is not asked to compute tax again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineItems(Order $order): array
    {
        // Relation loads re-apply BrandScope on the related model — escape it
        // per relation (.ai/rules): checkout runs where no brand host exists.
        return $order->lines()
            ->withoutGlobalScope(BrandScope::class)
            ->get()
            ->map(fn (OrderLine $line): array => [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => MinorUnits::fromDecimal($line->line_gross, $order->currency),
                    'product_data' => [
                        'name' => $line->label,
                    ],
                ],
                'quantity' => 1,
            ])
            ->values()
            ->all();
    }
}
