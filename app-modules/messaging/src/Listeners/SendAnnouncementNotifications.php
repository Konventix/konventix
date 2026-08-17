<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AnnouncementData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Models\Recipient;

/**
 * The intent-event exception (ADR-0041 point 8, D-CNT-08): Content
 * declares intent only — urgency and audience — and Messaging alone maps
 * that to channels and timing. Urgent bypasses digests and quiet hours;
 * a normal announcement coalesces per recipient through the
 * `content.announcements` topic's digest window.
 *
 * Queued (on Messaging's own queue): publishing an entry must not wait on
 * a fan-out across every known account. Both audience values reach every
 * account today — `everyone` vs `logged-in` only differs for the public
 * site, and Messaging can only ever reach accounts; per-brand audience
 * narrowing waits on an Identity signal for brand membership (TODO.md).
 */
final class SendAnnouncementNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'messaging';

    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(AnnouncementPublished $event): void
    {
        foreach (Recipient::query()->cursor() as $recipient) {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $recipient->id,
                templateKey: 'messaging.announcement',
                payload: new AnnouncementData(title: $event->title),
                brandId: $event->brandId,
                topicKey: 'content.announcements',
                urgent: $event->urgency === 'urgent',
            ));
        }
    }
}
