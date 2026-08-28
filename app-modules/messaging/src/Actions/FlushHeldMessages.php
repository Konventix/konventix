<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Messaging\Data\DigestData;
use Konventix\Messaging\Data\RehydratedPayload;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Templates\TemplateRenderer;

/**
 * The digest sweep (ADR-0041 point 5): flushes every held row whose
 * `hold_until` has passed. One held message per (brand, recipient, channel)
 * sends as itself; several coalesce into one `messaging.digest` message
 * listing each item's rendered subject in the recipient's locale.
 *
 * Coalesced member rows are marked `sent` with `digest_message_id` pointing
 * at the digest that carried their content — their disposition is decided
 * here; a transport failure afterwards shows on the digest's own log row,
 * which is the message that actually travelled.
 *
 * Due rows are claimed under row locks inside one transaction, so two
 * overlapping sweeps cannot double-send; jobs dispatch only after commit.
 */
final class FlushHeldMessages
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @return array{flushed: int, digests: int}
     */
    public function handle(): array
    {
        /**
         * @var array{0: list<SendMessageData>, 1: list<MessageQueued>} $result
         */
        $result = DB::transaction(function (): array {
            $due = MessageRecord::withoutBrandScope()
                ->where('status', MessageStatus::Held)
                ->where('hold_until', '<=', now())
                ->lockForUpdate()
                // `queued_at` alone ties whenever two holds land in the same
                // second — a real possibility for a burst of digestable
                // sends, not just a test racing the clock. `id` (a ULID) is
                // monotonically increasing even within one millisecond, so it
                // breaks the tie in actual creation order instead of leaving
                // it to Postgres' unspecified order for equal sort keys.
                ->orderBy('queued_at')
                ->orderBy('id')
                ->get();

            $dispatches = [];
            $queuedEvents = [];

            $groups = $due->groupBy(fn (MessageRecord $row): string => implode('|', [
                $row->brand_id,
                $row->recipient_id ?? $row->recipient_email,
                $row->channel->value,
            ]));

            foreach ($groups as $group) {
                if ($group->count() === 1) {
                    /** @var MessageRecord $row */
                    $row = $group->first();
                    $row->forceFill(['status' => MessageStatus::Queued, 'hold_until' => null])->save();

                    $dispatches[] = $this->envelopeFor($row);

                    continue;
                }

                [$digest, $queued] = $this->coalesce(array_values($group->all()));
                $dispatches[] = $digest;
                $queuedEvents[] = $queued;
            }

            return [$dispatches, $queuedEvents];
        });

        [$dispatches, $queuedEvents] = $result;

        foreach ($queuedEvents as $event) {
            event($event);
        }

        foreach ($dispatches as $envelope) {
            SendQueuedMessage::dispatch($envelope);
        }

        return [
            'flushed' => count($dispatches),
            'digests' => count($queuedEvents),
        ];
    }

    /**
     * Several held messages become one digest: a fresh log row (and a fresh
     * MessageQueued — the members had theirs at accept time) whose payload
     * lists each member's subject rendered exactly as an immediate send
     * would have.
     *
     * @param  list<MessageRecord>  $rows
     * @return array{0: SendMessageData, 1: MessageQueued}
     */
    private function coalesce(array $rows): array
    {
        $first = $rows[0];
        $digestId = (string) Str::ulid();

        $items = array_map(
            fn (MessageRecord $row): string => $this->renderer->render($this->envelopeFor($row))->subject,
            $rows,
        );

        $envelope = new SendMessageData(
            messageId: $digestId,
            brandId: $first->brand_id,
            recipientId: $first->recipient_id,
            recipientEmail: $first->recipient_email,
            recipientLocale: $first->recipient_locale ?? (string) config('app.locale'),
            templateKey: 'messaging.digest',
            payload: new DigestData(count: count($items), items: $items),
            channel: $first->channel->value,
        );

        $record = new MessageRecord([
            'id' => $digestId,
            'recipient_id' => $envelope->recipientId,
            'recipient_email' => $envelope->recipientEmail,
            'recipient_locale' => $envelope->recipientLocale,
            'channel' => $envelope->channel,
            'template_key' => $envelope->templateKey,
            'status' => MessageStatus::Queued,
            'payload' => $envelope->payload->toArray(),
            'queued_at' => now(),
        ]);
        $record->forceFill(['brand_id' => $envelope->brandId])->save();

        foreach ($rows as $row) {
            $row->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'digest_message_id' => $digestId,
            ])->save();
        }

        return [$envelope, new MessageQueued(
            messageId: $digestId,
            brandId: $envelope->brandId,
            recipientId: $envelope->recipientId,
            channel: $envelope->channel,
            templateKey: $envelope->templateKey,
        )];
    }

    /**
     * Rebuilds a dispatchable envelope from a held log row. The typed
     * payload no longer exists — `RehydratedPayload` hands the renderer the
     * array the log kept, which is exactly what the original `toArray()`
     * produced.
     */
    private function envelopeFor(MessageRecord $row): SendMessageData
    {
        return new SendMessageData(
            messageId: $row->id,
            brandId: $row->brand_id,
            recipientId: $row->recipient_id,
            recipientEmail: $row->recipient_email,
            recipientLocale: $row->recipient_locale ?? (string) config('app.locale'),
            templateKey: $row->template_key,
            payload: new RehydratedPayload($row->payload),
            channel: $row->channel->value,
            topicKey: $row->topic_key,
            urgent: $row->urgent,
        );
    }
}
