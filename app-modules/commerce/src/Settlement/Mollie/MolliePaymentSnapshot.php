<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

/**
 * What the adapter needs back from Mollie about one payment: Mollie's id
 * (`tr_…`), its current status, and — right after creation — the hosted
 * checkout URL to send the payer to.
 *
 * The reversal fields (ADR-0057 point 7) are the fetch-back's second half:
 * Mollie notifies a chargeback on the *paid* payment and says nothing about
 * it in the request body, so the controller re-reads the payment and finds
 * `amountChargedBack`, `amountRefunded` and the two lists here. They stay
 * null/empty on a snapshot built at creation time, where neither exists.
 */
final readonly class MolliePaymentSnapshot
{
    /**
     * @param  list<array{id: string, amount: string, reason: ?string, createdAt: string}>  $chargebacks
     * @param  list<array{id: string, amount: string, reason: ?string, createdAt: string}>  $refunds
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $checkoutUrl = null,
        public ?string $amountChargedBack = null,
        public ?string $amountRefunded = null,
        public array $chargebacks = [],
        public array $refunds = [],
    ) {}
}
