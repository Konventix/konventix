<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Konventix\Messaging\Data\ArrayPayloadData;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use LogicException;
use Spatie\LaravelData\DataCollection;

/**
 * ADR-0048 decision 6: an operator can see the delivery log and resend a
 * mail. Rebuilds a `SendMessageRequest` from a stored log row — recipient,
 * template key, the payload array `SendMessage::log()` kept, and any
 * attachment still on disk — and hands it straight back to `SendMessage`,
 * which logs a fresh row of its own (`MessageQueued` fires again, exactly
 * like an original send). The new row is then linked back with
 * `resent_from_id` so the log shows the pair.
 *
 * `urgent: true` — a resend is a deliberate operator action, not a
 * digestable background notification, so it bypasses quiet hours and
 * digest windows rather than joining a hold window with unrelated sends.
 *
 * Refuses a record still in flight (`Queued`/`Held`): resending it would
 * race the pipeline that has not finished with it yet, and there is
 * nothing to resend — it has not failed or suppressed anything.
 */
final class ResendMessage
{
    private const array IN_FLIGHT = [MessageStatus::Queued, MessageStatus::Held];

    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(MessageRecord $record, string $byUserId): MessageRecord
    {
        if (in_array($record->status, self::IN_FLIGHT, true)) {
            throw new LogicException(sprintf(
                'Message [%s] is still %s and cannot be resent yet.',
                $record->id,
                $record->status->value,
            ));
        }

        $envelopes = $this->sendMessage->handle(new SendMessageRequest(
            recipientId: $record->recipient_id,
            templateKey: $record->template_key,
            payload: new ArrayPayloadData($record->payload),
            recipientEmail: $record->recipient_email,
            recipientLocale: $record->recipient_locale,
            brandId: $record->brand_id,
            attachments: $this->resolveAttachments($record),
            topicKey: $record->topic_key,
            urgent: true,
        ));

        // SendMessage refuses silently-to-the-caller (loudly to the log,
        // via MessageSuppressed) when the recipient is still on the
        // suppression list or has opted out — no RuntimeException, just no
        // envelope. Nothing was queued, so there is nothing to link back
        // to; the operator needs to hear that in words, not a 500 from
        // dereferencing an envelope that never arrived.
        if ($envelopes === []) {
            throw new LogicException(__('The recipient is still suppressed; lift the suppression before resending.'));
        }

        /** @var MessageRecord $new */
        $new = MessageRecord::withoutBrandScope()->findOrFail($envelopes[0]->messageId);
        $new->forceFill(['resent_from_id' => $record->getKey()])->save();

        Log::info('Message resent by operator.', [
            'original_message_id' => $record->id,
            'new_message_id' => $new->id,
            'by_user_id' => $byUserId,
        ]);

        return $new;
    }

    /**
     * Only attachments still present on their disk ride along — a resend
     * of a message whose file has since been pruned or moved drops it with
     * a warning rather than failing the whole resend.
     *
     * @return DataCollection<int, AttachmentData>|null
     */
    private function resolveAttachments(MessageRecord $record): ?DataCollection
    {
        $stored = $record->attachments ?? [];

        if ($stored === []) {
            return null;
        }

        $kept = [];

        foreach ($stored as $attachment) {
            $disk = $attachment['disk'] ?? null;
            $path = $attachment['path'] ?? null;

            if (! is_string($disk) || ! is_string($path) || ! Storage::disk($disk)->exists($path)) {
                Log::warning('Resend dropped an attachment no longer on disk.', [
                    'message_id' => $record->id,
                    'disk' => $disk,
                    'path' => $path,
                ]);

                continue;
            }

            $kept[] = new AttachmentData(
                disk: $disk,
                path: $path,
                as: is_string($attachment['as'] ?? null) ? $attachment['as'] : basename($path),
                mime: is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null,
            );
        }

        return $kept === [] ? null : new DataCollection(AttachmentData::class, $kept);
    }
}
