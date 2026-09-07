<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\PaymentReversedMailData;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Settlement\RepaymentWindow;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
use RuntimeException;

/**
 * ADR-0057 point 8: Commerce owns `PaymentReversed`, so Commerce owns
 * telling the payer that the money left again (ADR-0048 point 3). The sale
 * stands and the order is waiting for the amount a second time, so the mail
 * carries the amount, the reason in plain words, the deadline of ADR-0057
 * point 4, and the link that takes the payer back to paying:
 * `commerce.pay.show` for a registration order, `/my/orders` for a cart
 * order, exactly as `SendPaymentFailedMailOnPaymentFailed` chooses.
 *
 * **The reason map never accuses.** `reasonCode` is the provider's own
 * string — a SEPA return code (`AM04`, `MD01`, …) or a Stripe dispute
 * reason (`insufficient_funds`, `fraudulent`, …) — and the sentences here
 * state what the bank reported, never what the payer did. An unknown code
 * gets a neutral sentence rather than a guess; the codes are a moving
 * target and a wrong translation of one would be worse than saying less.
 *
 * No payer (`payer_user_id` null) is logged and skipped, not an error: an
 * order an operator books manually may have none. A `SendMessage` failure
 * is caught the way `SendPaymentFailedMailOnPaymentFailed` catches one.
 */
final class SendPaymentReversedMailOnPaymentReversed
{
    /**
     * Provider reason code → the sentence the payer reads, in both
     * languages the templates carry.
     *
     * SEPA return codes (ISO 20022, as Mollie and the banks report them)
     * and Stripe dispute reasons share the map because both answer the same
     * question and a payer does not care which network produced the string.
     *
     * @var array<string, array{de: string, en: string}>
     */
    private const array REASONS = [
        // Not enough money on the account at the moment of the debit.
        'AM04' => [
            'de' => 'Die Bank meldet, dass das Konto zum Zeitpunkt der Abbuchung nicht ausreichend gedeckt war.',
            'en' => 'The bank reports that the account did not have sufficient funds at the time of the debit.',
        ],
        'insufficient_funds' => [
            'de' => 'Die Bank meldet, dass das Konto zum Zeitpunkt der Abbuchung nicht ausreichend gedeckt war.',
            'en' => 'The bank reports that the account did not have sufficient funds at the time of the debit.',
        ],
        // The account could not be debited at all.
        'AC01' => [
            'de' => 'Die Bankverbindung konnte nicht belastet werden.',
            'en' => 'The bank account could not be debited.',
        ],
        'AC04' => [
            'de' => 'Das Konto besteht nicht mehr.',
            'en' => 'The account no longer exists.',
        ],
        'AC06' => [
            'de' => 'Das Konto ist für Lastschriften gesperrt.',
            'en' => 'The account is blocked for direct debits.',
        ],
        'incorrect_account_details' => [
            'de' => 'Die Bankverbindung konnte nicht belastet werden.',
            'en' => 'The bank account could not be debited.',
        ],
        // No usable mandate for the debit.
        'MD01' => [
            'de' => 'Der Bank lag für die Abbuchung kein gültiges Mandat vor.',
            'en' => 'The bank had no valid mandate on file for the debit.',
        ],
        'debit_not_authorized' => [
            'de' => 'Der Bank lag für die Abbuchung kein gültiges Mandat vor.',
            'en' => 'The bank had no valid mandate on file for the debit.',
        ],
        // The account holder asked for the money back.
        'MD06' => [
            'de' => 'Die Abbuchung wurde bei der Bank widersprochen.',
            'en' => 'The debit was objected to at the bank.',
        ],
        'MS02' => [
            'de' => 'Die Abbuchung wurde bei der Bank widersprochen.',
            'en' => 'The debit was objected to at the bank.',
        ],
        'customer_initiated' => [
            'de' => 'Die Zahlung wurde bei der Bank widersprochen.',
            'en' => 'The payment was objected to at the bank.',
        ],
        // The payment was not recognised on the statement.
        'unrecognized' => [
            'de' => 'Die Zahlung wurde auf dem Kontoauszug nicht wiedererkannt.',
            'en' => 'The payment was not recognised on the account statement.',
        ],
        'fraudulent' => [
            'de' => 'Die Zahlung wurde bei der Bank als nicht autorisiert gemeldet.',
            'en' => 'The payment was reported to the bank as not authorised.',
        ],
        'duplicate' => [
            'de' => 'Die Zahlung wurde als doppelte Belastung gemeldet.',
            'en' => 'The payment was reported as a duplicate charge.',
        ],
        'product_not_received' => [
            'de' => 'Es wurde gemeldet, dass die Leistung nicht erbracht wurde.',
            'en' => 'It was reported that the service was not received.',
        ],
        'credit_not_processed' => [
            'de' => 'Es wurde gemeldet, dass eine erwartete Erstattung nicht angekommen ist.',
            'en' => 'It was reported that an expected refund did not arrive.',
        ],
    ];

    /**
     * What an unknown or missing code says. Deliberately says only what is
     * certain: the money came back, and the reason was not passed on.
     *
     * @var array{de: string, en: string}
     */
    private const array NEUTRAL_REASON = [
        'de' => 'Ein Grund wurde uns dabei nicht mitgeteilt.',
        'en' => 'No reason was passed on to us.',
    ];

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly BrandUrl $brandUrl,
        private readonly RepaymentWindow $window,
    ) {}

    public function handle(PaymentReversed $event): void
    {
        $order = Order::withoutBrandScope()->whereKey($event->orderId)->first();

        if ($order === null) {
            return;
        }

        if ($order->payer_user_id === null) {
            Log::warning('Payment reversed on an order with no payer; skipping payment-reversed mail.', [
                'order_id' => $order->id,
                'brand_id' => $event->brandId,
            ]);

            return;
        }

        try {
            $this->send($event, $order);
        } catch (RuntimeException $exception) {
            Log::warning('Payment-reversed mail could not be sent.', [
                'order_id' => $order->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function send(PaymentReversed $event, Order $order): void
    {
        $reason = self::REASONS[$event->reasonCode ?? ''] ?? self::NEUTRAL_REASON;

        $payUrl = $order->settles_type === 'registration'
            ? $this->absoluteUrl($event->brandId, 'commerce.pay.show', ['registration' => $order->settles_id])
            : $this->absoluteUrl($event->brandId, 'commerce.mine.index');

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: $order->payer_user_id,
            templateKey: 'commerce.payment-reversed',
            payload: new PaymentReversedMailData(
                orderNumberShort: strtoupper(substr($order->id, -8)),
                amount: $event->amount,
                currency: $event->currency,
                reasonDe: $reason['de'],
                reasonEn: $reason['en'],
                dueOn: $this->dueOn($event),
                payUrl: $payUrl,
                ordersUrl: $this->absoluteUrl($event->brandId, 'commerce.mine.index'),
            ),
            brandId: $event->brandId,
        ));
    }

    /**
     * `repayDueOn` is always set for the reversals that emit this event —
     * `RecordPaymentReversal` writes it in the same breath. The fallback
     * computes the same number from the brand's own window rather than
     * leaving the payer without a date: a mail that names no deadline
     * cannot be acted on before the sweep cancels the order.
     */
    private function dueOn(PaymentReversed $event): string
    {
        $dueOn = $event->repayDueOn !== null
            ? CarbonImmutable::parse($event->repayDueOn)
            : $this->window->dueOnFrom(CarbonImmutable::parse($event->reversedAt), $event->brandId);

        return $dueOn->format('d.m.Y');
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function absoluteUrl(string $brandId, string $routeName, array $parameters = []): string
    {
        $brand = Brand::query()->find($brandId);
        $base = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $base === null
            ? route($routeName, $parameters, absolute: true)
            : rtrim($base, '/').route($routeName, $parameters, absolute: false);
    }
}
