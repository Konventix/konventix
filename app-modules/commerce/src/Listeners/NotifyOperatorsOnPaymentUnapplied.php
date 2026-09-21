<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Operations\AdminUrl;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;

/**
 * ADR-0057 point 9 / #6: money arrived for an order the checkout-grace
 * sweep had already cancelled. The payment is recorded `succeeded` — it
 * really moved — and nothing else happens automatically: the operator may
 * prefer to reinstate the registration while the quota allows, or to refund
 * through the payment's own account. This notice is what puts that decision
 * in front of a human, on the same `commerce.operations` topic every other
 * shop-operator notice uses.
 *
 * No payer mail: telling the payer "your money arrived and we did nothing
 * with it" before an operator has decided would be worse than saying
 * nothing.
 */
final class NotifyOperatorsOnPaymentUnapplied
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly AdminUrl $adminUrl,
    ) {}

    public function handle(PaymentUnapplied $event): void
    {
        $order = Order::withoutBrandScope()->whereKey($event->orderId)->first();

        if ($order === null) {
            return;
        }

        $orderNumberShort = strtoupper(substr($order->id, -8));
        $title = "Zahlung ohne offene Bestellung: {$orderNumberShort}";
        $detail = sprintf(
            '%s. %s %s sind über %s eingegangen, die Bestellung steht auf "%s". Ob erstattet oder die Anmeldung wiederhergestellt wird, entscheidet der Veranstalter.',
            $title,
            $event->amount,
            $event->currency,
            $event->provider,
            $order->status->value,
        );

        $url = $this->adminUrl->to($event->brandId, 'admin.orders.show', ['order' => $order->id]);

        $holders = $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::ManageProducts->value);

        foreach ($holders as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::PaymentUnapplied,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    brandId: $event->brandId,
                    topicKey: 'commerce.operations',
                    // Money nobody has applied is worth an immediate nudge:
                    // the quota that would let the registration be
                    // reinstated does not wait for a digest.
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Unapplied-payment operations notice could not be sent.', [
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'payment_id' => $event->paymentId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
