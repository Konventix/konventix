<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\PaymentFailedMailData;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Settlement\PayLink;
use Konventix\Commerce\Settlement\PaymentWindow;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use RuntimeException;

/**
 * ADR-0048 point 3: Commerce owns `PaymentFailed`, so Commerce owns telling
 * the payer their attempt did not go through — the order stays pending, and
 * the mail's own retry link says where to try again.
 *
 * That link is `Settlement\PayLink`'s answer since #5 — `commerce.pay.show`
 * for a registration order (ADR-0039's public checkout), the order's own
 * `/pay/order/{order}` page for a cart order (ADR-0047) — so the failure
 * mail and the order confirmation always name the same place.
 *
 * `reasonCode` values come from the three PSP webhook controllers that
 * actually call `RecordPaymentFailure` — `Http\Controllers\Api\*WebhookController`
 * emit `async_payment_failed`, `checkout_expired`, `failed`, `canceled`,
 * `expired`. No adapter emits `card_declined`; `reasonSentence()` maps the
 * real values instead of the illustrative wording sketched during planning.
 *
 * No payer (`payer_user_id` null) is logged and skipped, not an error: a
 * pre-ADR-0044 order or one an operator books manually may have none. A
 * `SendMessage` failure is caught the same way
 * `PromoteFromWaitlist::notify()` catches one.
 */
final class SendPaymentFailedMailOnPaymentFailed
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly BrandUrl $brandUrl,
    ) {}

    public function handle(PaymentFailed $event): void
    {
        $order = Order::withoutBrandScope()->whereKey($event->orderId)->first();

        if ($order === null) {
            return;
        }

        if ($order->payer_user_id === null) {
            Log::warning('Payment failed on an order with no payer; skipping payment-failed mail.', [
                'order_id' => $order->id,
                'brand_id' => $event->brandId,
            ]);

            return;
        }

        try {
            $this->send($event, $order);
        } catch (RuntimeException $exception) {
            Log::warning('Payment-failed mail could not be sent.', [
                'order_id' => $order->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function send(PaymentFailed $event, Order $order): void
    {
        // GitLab #5: `PayLink` owns the rule — a registration order's public
        // checkout, every other order's own `/pay/order/{order}` page.
        // Shared with the order confirmation so a payer is never sent to two
        // different places for one order.
        $link = app(PayLink::class)->forOrder($order);

        $retryUrl = $link === null
            ? $this->absoluteUrl($event->brandId, 'commerce.mine.index')
            : $this->absoluteUrl($event->brandId, $link['route'], $link['parameters']);

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: $order->payer_user_id,
            templateKey: 'commerce.payment-failed',
            payload: new PaymentFailedMailData(
                orderNumberShort: strtoupper(substr($order->id, -8)),
                reason: $this->reasonSentence($event->reasonCode),
                retryUrl: $retryUrl,
                ordersUrl: $this->absoluteUrl($event->brandId, 'commerce.mine.index'),
                // #131, whole-branch review finding 2: the retry link is
                // worth nothing if the payer does not know it has a
                // deadline. `Settlement\PaymentWindow` is the same answer
                // `ExpireRegistrationOrders` acts on, so this mail cannot
                // invite a payment the sweep is about to orphan without
                // saying when it stops. Rendered in the brand's own
                // timezone (`ResolveBrandTimezone`, the same setting
                // `Digests\HoldPolicy` reads for quiet hours) rather than
                // the application's UTC — the pay page states the same
                // instant in the viewer's own zone, and a mail that named
                // a different clock time for it would read as a different
                // deadline.
                payBy: $this->formatPayBy($order),
            ),
            brandId: $event->brandId,
        ));
    }

    private function formatPayBy(Order $order): ?string
    {
        $deadline = app(PaymentWindow::class)->endsFor($order);

        if ($deadline === null) {
            return null;
        }

        $timezone = app(ResolveBrandTimezone::class)->handle($order->brand_id);

        return app(OperatorDateTimeFormat::class)->dateTime($deadline->setTimezone($timezone));
    }

    private function reasonSentence(string $reasonCode): string
    {
        return match ($reasonCode) {
            'expired', 'checkout_expired' => 'Die Zahlung ist abgelaufen.',
            'canceled' => 'Die Zahlung wurde abgebrochen.',
            default => 'Die Zahlung konnte nicht abgeschlossen werden.',
        };
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
