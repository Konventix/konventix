<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Events\NotificationReceived;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Templates\RenderedMessage;
use RuntimeException;

/**
 * The in-app channel (ADR-0041 point 6): delivery writes the inbox row —
 * title and body are the rendered template, exactly what mail would have
 * said — then pushes `notification.received` to the recipient's browser
 * over Reverb so the bell updates without a page load.
 *
 * A payload carrying a string `url` key becomes the notification's action
 * URL; everything else about the payload stays in the delivery log. A
 * digest has no such key and never gets one (Round 4 Task 27): it points at
 * several things at once, so its row's action URL stays null and the links
 * live per item inside the rendered body.
 */
final class InAppChannel implements MessageChannel
{
    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        if ($message->recipientId === null) {
            // Unreachable through SendMessage (the channel requires a
            // recipient id by construction) — guarded for direct dispatch.
            throw new RuntimeException(sprintf(
                'In-app envelope [%s] has no recipient id; an inbox row needs an account to attach to.',
                $message->messageId,
            ));
        }

        $payload = $message->payload->toArray();
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : null;

        $notification = new InboxNotification([
            'recipient_id' => $message->recipientId,
            'message_id' => $message->messageId,
            'topic_key' => $message->topicKey,
            'title' => $rendered->subject,
            'body' => $rendered->body,
            'url' => $url,
        ]);
        // Brand from the envelope, not ambient context (ADR-0010): the
        // messaging queue worker delivers for every brand.
        $notification->forceFill(['brand_id' => $message->brandId])->save();

        event(new NotificationReceived(
            notificationId: $notification->getKey(),
            recipientId: $message->recipientId,
            brandId: $message->brandId,
            topicKey: $message->topicKey,
            title: $rendered->subject,
            body: $rendered->body,
            url: $url,
            createdAt: $notification->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ));
    }
}
