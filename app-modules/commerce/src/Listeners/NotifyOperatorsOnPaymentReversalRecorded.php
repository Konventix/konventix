<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Models\Order;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;

/**
 * ADR-0057 point 8: the operator's half of every reversal, on the same
 * `commerce.operations` topic and through the same one template
 * `NotifyOperatorsOnPaymentFailed` uses.
 *
 * `PaymentReversalRecorded` is module-internal and fires on *every* row and
 * every transition, so this listener is where the ADR's two notice kinds are
 * decided:
 *
 * - an **open dispute** → `dispute-opened`. Nothing downstream moves; the
 *   money may come back, the person may legitimately be at the event, and
 *   evidence is filed in the provider's own dashboard (ADR-0039 point 5).
 *   The operator has to know it is running.
 * - a **final return or lost dispute** → `payment-reversed`. The receivable
 *   revived (ADR-0057 point 3) and a deadline is now counting.
 * - a **withdrawn** dispute → nothing. The organizer won and the order
 *   never left `paid`; a notice would be news about no change.
 * - an **external refund** → nothing here. It is the operator's own action
 *   in the provider's dashboard, and `RecordPaymentReversal` already logs
 *   that ADR-0037's check-in guard could not run for it.
 *
 * A *partial* return still notifies: nothing else moves for it (#35), so
 * the notice is the only thing that tells anyone it happened.
 *
 * The admin order link is absolute through
 * `OperatorSettings::adminBaseUrlForBrand()` — see
 * `NotifyOperatorsOnStockExhausted`'s docblock for why that doorway.
 */
final class NotifyOperatorsOnPaymentReversalRecorded
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    public function handle(PaymentReversalRecorded $event): void
    {
        $noticeKind = $this->noticeKindFor($event);

        if ($noticeKind === null) {
            return;
        }

        $order = Order::withoutBrandScope()->whereKey($event->orderId)->first();

        if ($order === null) {
            return;
        }

        $orderNumberShort = strtoupper(substr($order->id, -8));

        $title = $noticeKind === OperationsNoticeKind::DisputeOpened
            ? "Dispute eröffnet: Bestellung {$orderNumberShort}"
            : "Zahlung zurückgebucht: Bestellung {$orderNumberShort}";

        $detail = $title.'. '.$this->detailSentence($noticeKind, $event);
        $url = $this->adminUrl($event->brandId, 'admin.orders.show', ['order' => $order->id]);

        $holders = $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::ManageProducts->value);

        foreach ($holders as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: $noticeKind,
                        title: $title,
                        detail: $detail,
                        url: $url,
                    ),
                    brandId: $event->brandId,
                    topicKey: 'commerce.operations',
                    // Money that left again is worth an immediate nudge, not
                    // a 30-minute digest — the same reasoning
                    // `NotifyOperatorsOnPaymentFailed` states.
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Payment-reversal operations notice could not be sent.', [
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'reversal_id' => $event->reversalId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function noticeKindFor(PaymentReversalRecorded $event): ?OperationsNoticeKind
    {
        $kind = ReversalKind::tryFrom($event->kind);
        $status = ReversalStatus::tryFrom($event->status);

        if ($kind === ReversalKind::ExternalRefund) {
            return null;
        }

        return match ($status) {
            ReversalStatus::Open => $kind === ReversalKind::Dispute ? OperationsNoticeKind::DisputeOpened : null,
            ReversalStatus::Final => OperationsNoticeKind::PaymentReversed,
            default => null,
        };
    }

    /**
     * The provider's own reason code, verbatim: the operator is the person
     * who has to file evidence in the provider's dashboard, and the raw
     * code is what that dashboard shows. The payer's mail is where the code
     * becomes a sentence (`SendPaymentReversedMailOnPaymentReversed`).
     */
    private function detailSentence(OperationsNoticeKind $kind, PaymentReversalRecorded $event): string
    {
        $reason = $event->reasonCode === null || $event->reasonCode === ''
            ? 'ohne Grundangabe'
            : "Grund: {$event->reasonCode}";

        return $kind === OperationsNoticeKind::DisputeOpened
            ? sprintf(
                '%s %s hält %s %s zurück (%s). Beweise werden im Dashboard des Anbieters eingereicht.',
                'Der Anbieter',
                $event->provider,
                $event->amount,
                $event->currency,
                $reason,
            )
            : sprintf(
                '%s %s wurden über %s zurückgebucht (%s). Die Forderung besteht wieder.',
                $event->amount,
                $event->currency,
                $event->provider,
                $reason,
            );
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
