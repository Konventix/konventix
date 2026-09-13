<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The real `StripeApi`: a thin pass-through to the Stripe SDK, built on a
 * `StripeClient` instance (never the deprecated global key). Deliberately
 * close to free of logic; everything decidable about *what a provider
 * answer means* lives in `StripeSettlementAdapter` or its callers, tested
 * against a fake of the interface instead of this class. The one exception
 * is exception translation itself (#286): only this file may `use
 * Stripe\Exception\…`, so a vendor failure that is actually a definite
 * answer — "this session does not exist" — is turned into a plain
 * exception here, the same way a session without a hosted URL already
 * became a `RuntimeException` below rather than an SDK oddity leaking out.
 *
 * The client carries this instance's own secret key
 * (`CommerceServiceProvider`'s binding, resolved by `StripeCredentials`),
 * and every call below is made on that account — no `stripe_account`
 * request option anywhere, because there is no connected account to act on
 * (ADR-0058).
 */
final class SdkStripeApi implements StripeApi
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $session = $this->client->checkout->sessions->create($params);

        if ($session->url === null) {
            throw new RuntimeException(sprintf(
                'Stripe returned checkout session [%s] without a hosted payment URL.',
                $session->id,
            ));
        }

        return new StripeCheckoutSession(id: $session->id, url: $session->url);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function createRefund(array $params): string
    {
        return $this->client->refunds->create($params)->id;
    }

    /**
     * Two list calls, both filtered by the intent — the cheapest way to ask
     * "did anything take this money back?" (#235). Neither list is paged:
     * one payment intent carries at most a handful of disputes and refunds,
     * and a page of a hundred is far more than any real payment has.
     *
     * @return array{
     *     disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>,
     *     refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>,
     * }
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        $disputes = [];

        foreach ($this->client->disputes->all(['payment_intent' => $paymentIntentId, 'limit' => 100])->data as $dispute) {
            $fee = $dispute->balance_transactions[0]->fee ?? null;

            $disputes[] = [
                'id' => $dispute->id,
                'status' => $dispute->status,
                'amount' => (int) $dispute->amount,
                'currency' => (string) $dispute->currency,
                // Typed non-null by the SDK, unlike a refund's reason.
                'reason' => $dispute->reason,
                'fee' => is_int($fee) ? $fee : null,
                'created' => $dispute->created,
            ];
        }

        $refunds = [];

        foreach ($this->client->refunds->all(['payment_intent' => $paymentIntentId, 'limit' => 100])->data as $refund) {
            $refunds[] = [
                'id' => $refund->id,
                'amount' => (int) $refund->amount,
                'currency' => (string) $refund->currency,
                'reason' => is_string($refund->reason) ? $refund->reason : null,
                // The charge the refund belongs to, so the catch-up can tell
                // that `charge.refunded` already recorded this money under
                // the charge id (fix round 1, review finding 3).
                'charge' => is_string($refund->charge) ? $refund->charge : null,
                'created' => $refund->created,
            ];
        }

        return ['disputes' => $disputes, 'refunds' => $refunds];
    }

    /**
     * The intent is expanded rather than left as an id: a delayed
     * notification method (SEPA, bank transfer) completes the session while
     * it is still `unpaid`, and only the intent's own status distinguishes
     * "still processing" from "the attempt was refused" — the fact
     * `checkout.session.async_payment_failed` would have carried.
     *
     * A session Stripe no longer holds — "No such checkout.session", the
     * `resource_missing` error code — is a definite answer, not a transport
     * failure, so it is translated into `StripeSessionNotFoundException`
     * rather than left as the SDK's `InvalidRequestException` (#286). Any
     * other invalid-request error is a real fault and travels on unchanged.
     *
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     *
     * @throws StripeSessionNotFoundException when Stripe no longer holds the session.
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        try {
            $session = $this->client->checkout->sessions->retrieve(
                $sessionId,
                ['expand' => ['payment_intent']],
            );
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            throw StripeSessionNotFoundException::forSession($sessionId, $exception);
        }

        $intent = $session->payment_intent;

        return [
            // The SDK types `status` nullable (a session that has not been
            // categorised yet); `payment_status` is always one of `paid`,
            // `unpaid`, `no_payment_required`.
            'status' => $session->status ?? 'unknown',
            'payment_status' => $session->payment_status,
            // Expanded it is the object; a session Stripe declined to
            // expand still carries the plain id, which is what a refund
            // needs.
            'payment_intent' => $intent instanceof PaymentIntent ? $intent->id : (is_string($intent) ? $intent : null),
            'payment_intent_status' => $intent instanceof PaymentIntent ? $intent->status : null,
        ];
    }
}
