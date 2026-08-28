<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Models\Order;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;

/**
 * ADR-0048 point 3: the operators' half of `PaymentFailed` —
 * `SendPaymentFailedMailOnPaymentFailed` tells the payer, this tells every
 * `commerce.manage-products` holder on the brand, on the same
 * `commerce.operations` topic `NotifyOperatorsOnStockExhausted` uses. Runs
 * regardless of whether the payer mail sent: an order with no payer still
 * needs the shop manager's attention.
 *
 * The admin order link is absolute through `OperatorSettings::adminBaseUrlForBrand()`
 * — see `NotifyOperatorsOnStockExhausted`'s docblock for why that doorway
 * and not the `Operator` model directly.
 */
final class NotifyOperatorsOnPaymentFailed
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    public function handle(PaymentFailed $event): void
    {
        $order = Order::withoutBrandScope()->whereKey($event->orderId)->first();

        if ($order === null) {
            return;
        }

        $orderNumberShort = strtoupper(substr($order->id, -8));
        $title = "Zahlung fehlgeschlagen: Bestellung {$orderNumberShort}";
        $detail = $title.'. '.$this->reasonSentence($event->reasonCode);
        $url = $this->adminUrl($event->brandId, 'admin.orders.show', ['order' => $order->id]);

        $holders = $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::ManageProducts->value);

        foreach ($holders as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::PaymentFailed,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    recipientLocale: 'de',
                    brandId: $event->brandId,
                    topicKey: 'commerce.operations',
                    // See NotifyOperatorsOnStockExhausted's docblock: a
                    // failed payment is worth an immediate nudge, not a
                    // batched digest, even though the topic itself stays
                    // digestable.
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Payment-failed operations notice could not be sent.', [
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Same mapping as `SendPaymentFailedMailOnPaymentFailed::reasonSentence()`
     * — real `reasonCode` values from the PSP webhook controllers, not the
     * illustrative wording sketched during planning.
     */
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
    private function adminUrl(string $brandId, string $routeName, array $parameters): string
    {
        $base = $this->operatorSettings->adminBaseUrlForBrand($brandId);
        $path = route($routeName, $parameters, absolute: false);

        return $base === null ? $path : rtrim($base, '/').$path;
    }
}
