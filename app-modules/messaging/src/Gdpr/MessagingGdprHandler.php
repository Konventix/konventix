<?php

declare(strict_types=1);

namespace Konventix\Messaging\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Models\PushSubscription;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Models\Suppression;

/**
 * Messaging's contribution to GDPR export and deletion.
 *
 * Everything here is data about reaching a person, which is why deletion is
 * unusually thorough: an address that survives an anonymization is an address
 * something will eventually send to.
 *
 * - The recipient read model is scrubbed but kept (tombstone): a delivery log
 *   row points at it.
 * - Push subscriptions and inbox notifications are **deleted outright**. A
 *   push endpoint is a live channel to a device, and an inbox notification is
 *   a message body sitting in an account nobody owns any more; neither has a
 *   reason to exist after the person is gone, and neither is referenced by
 *   anything.
 * - Delivery-log rows keep their shape — they are the record that something
 *   was sent, subject to its own 12-month retention (D-CPL-01) — but lose the
 *   address and any rendered body.
 * - Suppressions are deliberately **not** cleared: a suppression exists
 *   because mail to that address bounced or was complained about, and
 *   forgetting that would start sending again to an address that already said
 *   no. The email column is scrubbed only when it is not the suppression key.
 *
 * Idempotent throughout.
 */
final class MessagingGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'messaging';
    }

    public function exportFor(string $subjectId, string $brandId): array
    {
        /** @var Recipient|null $recipient */
        $recipient = Recipient::query()->find($subjectId);

        return [
            'recipient' => $recipient === null ? null : [
                'email' => $recipient->email,
                'display_name' => $recipient->display_name,
                'locale' => $recipient->locale,
                'timezone' => $recipient->timezone,
            ],
            'notification_preferences' => NotificationPreference::query()
                ->where('recipient_id', $subjectId)
                ->get()
                ->map(fn (NotificationPreference $preference): array => [
                    'topic' => $preference->topic_key,
                    'channel' => $preference->channel->value,
                    'enabled' => $preference->enabled,
                ])
                ->all(),
            'messages' => MessageRecord::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('recipient_id', $subjectId)
                ->orderBy('queued_at')
                ->get()
                ->map(fn (MessageRecord $record): array => [
                    'template' => $record->template_key,
                    'channel' => $record->channel->value,
                    'status' => $record->status->value,
                    'queued_at' => $record->queued_at->toIso8601String(),
                    'sent_at' => $record->sent_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
    {
        PushSubscription::query()->where('recipient_id', $subjectId)->delete();

        InboxNotification::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('recipient_id', $subjectId)
            ->delete();

        MessageRecord::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('recipient_id', $subjectId)
            ->update([
                'recipient_email' => 'anonymized-'.$subjectId.'@invalid',
                'rendered_subject' => null,
                'rendered_body' => null,
            ]);

        // Kept as a suppression, stripped of the account link: the address
        // itself is the key that stops a future send.
        Suppression::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('recipient_id', $subjectId)
            ->update(['recipient_id' => null]);

        Recipient::query()->whereKey($subjectId)->update([
            'email' => 'anonymized-'.$subjectId.'@invalid',
            'display_name' => 'Deleted account',
        ]);
    }
}
