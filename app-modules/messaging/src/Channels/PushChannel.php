<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Contracts\PushSender;
use Konventix\Messaging\Data\PushMessageData;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Models\PushSubscription;
use Konventix\Messaging\Templates\RenderedMessage;
use RuntimeException;

/**
 * The web-push channel (ADR-0041 point 7, D-MSG-02): delivers the typed
 * payload — title, plain-text body, URL, tag — to every device the
 * recipient subscribed. Endpoints the push service reports gone (404/410)
 * are pruned on the spot. A recipient with no subscribed device is a
 * no-op, not a failure: "no devices" is a fact about the recipient, not
 * about delivery.
 *
 * The send fails (and the job retries) only when every surviving endpoint
 * refused — partial delivery is delivery; a phone that is offline gets
 * the message from the push service later.
 */
final class PushChannel implements MessageChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        if ($message->recipientId === null) {
            // Unreachable through SendMessage (the channel requires a
            // recipient id by construction) — guarded for direct dispatch.
            throw new RuntimeException(sprintf(
                'Push envelope [%s] has no recipient id; subscriptions are per account device.',
                $message->messageId,
            ));
        }

        $subscriptions = PushSubscription::query()
            ->where('recipient_id', $message->recipientId)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payloadArray = $message->payload->toArray();

        $push = new PushMessageData(
            title: $rendered->subject,
            body: trim(strip_tags($rendered->body)),
            url: is_string($payloadArray['url'] ?? null) ? $payloadArray['url'] : null,
            tag: $message->topicKey,
        );

        $delivered = 0;
        $lastReason = null;

        foreach ($subscriptions as $subscription) {
            $report = $this->sender->send($subscription, $push);

            if ($report->expired) {
                $subscription->delete();

                continue;
            }

            if ($report->success) {
                $delivered++;
            } else {
                $lastReason = $report->reason;
            }
        }

        if ($delivered === 0 && $lastReason !== null) {
            throw new RuntimeException(sprintf(
                'Push delivery for [%s] failed on every endpoint: %s',
                $message->messageId,
                $lastReason,
            ));
        }
    }
}
