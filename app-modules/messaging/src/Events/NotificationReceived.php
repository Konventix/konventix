<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Browser push for the bell (ADR-0041 point 6, ADR-0025): broadcast as
 * `notification.received` on the recipient's private channel over Reverb —
 * the channel `routes/channels.php` already authorizes. `ShouldBroadcastNow`
 * because this fires inside the messaging queue job; a queued broadcast
 * would only add a second hop to say the same thing.
 *
 * A broadcast to the browser, not a module contract event — modules react
 * to `MessageQueued`/`MessageDelivered`, never to this.
 */
final class NotificationReceived implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $recipientId,
        public readonly string $brandId,
        public readonly ?string $topicKey,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly string $createdAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('Konventix.Identity.Models.User.'.$this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'topicKey' => $this->topicKey,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'readAt' => null,
            'createdAt' => $this->createdAt,
        ];
    }
}
