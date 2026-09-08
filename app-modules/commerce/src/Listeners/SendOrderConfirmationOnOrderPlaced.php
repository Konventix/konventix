<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OrderConfirmationMailData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Settlement\PayLink;
use Konventix\Commerce\Settlement\PaymentWindow;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use RuntimeException;

/**
 * ADR-0048 point 3: the module that owns an event owns its mail — a cart
 * checkout's own order confirmation, sent the moment `PlaceOrderFromCart`
 * places the order (`OrderPlaced`, `settlesType: 'cart'`).
 *
 * A direct order (`settlesType: 'registration'`) gets no confirmation here:
 * Registration already tells the registrant their submission went through,
 * and — where invoice-settled — `PlaceOrderFromSettlementRequest` mails the
 * Rechnung itself moments later. A second "your order" mail on top of
 * either would be noise, not news.
 *
 * The payer is always an account holder for a cart order (ADR-0044, a cart
 * is person-owned from the moment it opens) — `payer_user_id` missing here
 * would be a data bug, not a legitimate "no address" case, so it is logged
 * and skipped rather than silently accepted. A `SendMessage` failure (no
 * resolvable address in the recipient read model) is caught the same way
 * `PromoteFromWaitlist::notify()` catches one — the order stands either
 * way.
 */
final class SendOrderConfirmationOnOrderPlaced
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly BrandUrl $brandUrl,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        if ($event->settlesType !== 'cart') {
            return;
        }

        $order = Order::withoutBrandScope()->whereKey($event->orderId)->with('lines')->first();

        if ($order === null) {
            return;
        }

        if ($order->payer_user_id === null) {
            Log::warning('Cart order placed with no payer; skipping order confirmation mail.', [
                'order_id' => $order->id,
                'brand_id' => $event->brandId,
            ]);

            return;
        }

        try {
            $this->send($event, $order);
        } catch (RuntimeException $exception) {
            Log::warning('Order confirmation mail could not be sent.', [
                'order_id' => $order->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function send(OrderPlaced $event, Order $order): void
    {
        // #131, whole-branch review finding 2: one answer for "how long is
        // this payable", shared with the pay pages, the payment-failed mail
        // and the sweep that acts on it — a cart order's checked-out cart
        // expiry here, a direct registration order's sixty minutes there.
        $payableUntil = app(PaymentWindow::class)->endsFor($order);

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: $order->payer_user_id,
            templateKey: 'commerce.order-confirmation',
            payload: new OrderConfirmationMailData(
                orderNumberShort: strtoupper(substr($order->id, -8)),
                recipientName: $order->billing_party?->displayName(),
                placedAt: app(OperatorDateTimeFormat::class)->dateTime(Carbon::parse($event->placedAt)),
                lines: array_values($order->lines
                    ->map(fn (OrderLine $line): array => [
                        'label' => $line->label,
                        'quantity' => $line->quantity,
                        'lineGross' => (string) $line->line_gross,
                    ])
                    ->all()),
                totalGross: $order->total_gross,
                currency: $order->currency,
                paid: $order->status === OrderStatus::Paid,
                reservationUntil: $payableUntil === null ? null : app(OperatorDateTimeFormat::class)->dateTime($payableUntil),
                ordersUrl: $this->absoluteUrl($event->brandId, 'commerce.mine.index'),
                // GitLab #5: an unpaid order's confirmation says where to pay
                // it. `PayLink` owns the rule so this mail and the
                // payment-failed one cannot point at different places.
                payUrl: $this->payUrl($event->brandId, $order),
            ),
            brandId: $event->brandId,
        ));
    }

    private function payUrl(string $brandId, Order $order): ?string
    {
        $link = app(PayLink::class)->forOrder($order);

        return $link === null ? null : $this->absoluteUrl($brandId, $link['route'], $link['parameters']);
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
