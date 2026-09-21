<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Messaging\Data\DigestData;
use Konventix\Messaging\Data\DigestItemData;
use Konventix\Messaging\Data\RehydratedPayload;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Mail\MailQuota;
use Konventix\Messaging\Mail\MailQuotaScope;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Templates\TemplateRenderer;

/**
 * The digest sweep (ADR-0041 point 5): flushes every held row whose
 * `hold_until` has passed. One held message per (brand, recipient, channel)
 * sends as itself; several coalesce into one `messaging.digest` message
 * listing each item's rendered subject — linked to that member's own
 * payload `url` where it had one (Round 4 Task 27) — in the recipient's
 * locale.
 *
 * Coalesced member rows are marked `sent` with `digest_message_id` pointing
 * at the digest that carried their content — their disposition is decided
 * here; a transport failure afterwards shows on the digest's own log row,
 * which is the message that actually travelled.
 *
 * Quota holds (`hold_reason = mail_quota`, `Mail\MailQuota`, Task 14) are
 * different animals: each is a message that was ready to go and merely
 * waited for the window, so it is released as itself and never coalesced
 * with anything — an order confirmation must not arrive as a line in a
 * digest. Every mail this sweep releases, quota hold or digest, is checked
 * against the quota again at release time: a window that filled up in the
 * meantime re-holds the row (`hold_until` moved to the next free window)
 * instead of letting the whole backlog burst onto the transport. The sweep
 * counts its own releases per budget while it runs, because the counter
 * only moves once the job has actually sent — without that tally one run
 * would release an hour's worth against a counter still reading zero. A
 * digest whose window is exhausted is written `held` under the same reason
 * and goes out as itself on a later sweep.
 *
 * Due rows are claimed under row locks inside one transaction, so two
 * overlapping sweeps cannot double-send; jobs dispatch only after commit.
 */
final class FlushHeldMessages
{
    /** @var array<string, int> mail released this run, per quota scope key */
    private array $released = [];

    /** @var array<string, MailQuotaScope> */
    private array $scopes = [];

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly MailQuota $quota,
    ) {}

    /**
     * @return array{flushed: int, digests: int, reheld: int}
     */
    public function handle(): array
    {
        $this->released = [];
        $this->scopes = [];

        /**
         * @var array{0: list<SendMessageData>, 1: list<MessageQueued>, 2: int} $result
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
            $reheld = 0;

            $groups = $due->groupBy(fn (MessageRecord $row): string => implode('|', [
                $row->brand_id,
                $row->recipient_id ?? $row->recipient_email,
                $row->channel->value,
            ]));

            foreach ($groups as $group) {
                /** @var Collection<int, MessageRecord> $quotaHolds */
                /** @var Collection<int, MessageRecord> $digestHolds */
                [$quotaHolds, $digestHolds] = $group->partition(
                    fn (MessageRecord $row): bool => $row->hold_reason === MailQuota::HOLD_REASON,
                );

                // Never coalesced: each one goes out as the message it is.
                foreach ($quotaHolds as $row) {
                    $envelope = $this->release($row);

                    if ($envelope === null) {
                        $reheld++;
                    } else {
                        $dispatches[] = $envelope;
                    }
                }

                if ($digestHolds->count() === 1) {
                    /** @var MessageRecord $row */
                    $row = $digestHolds->first();
                    $envelope = $this->release($row);

                    if ($envelope === null) {
                        $reheld++;
                    } else {
                        $dispatches[] = $envelope;
                    }

                    continue;
                }

                if ($digestHolds->count() > 1) {
                    [$digest, $queued] = $this->coalesce(array_values($digestHolds->all()));
                    $queuedEvents[] = $queued;

                    if ($digest === null) {
                        $reheld++;
                    } else {
                        $dispatches[] = $digest;
                    }
                }
            }

            return [$dispatches, $queuedEvents, $reheld];
        });

        [$dispatches, $queuedEvents, $reheld] = $result;

        foreach ($queuedEvents as $event) {
            event($event);
        }

        foreach ($dispatches as $envelope) {
            SendQueuedMessage::dispatch($envelope);
        }

        return [
            'flushed' => count($dispatches),
            'digests' => count($queuedEvents),
            'reheld' => $reheld,
        ];
    }

    /**
     * Lets a due row go — or, for a mail whose budget is spent, moves its
     * hold to the next free window and returns null.
     */
    private function release(MessageRecord $row): ?SendMessageData
    {
        if ($this->reholdForQuota($row)) {
            return null;
        }

        $row->forceFill(['status' => MessageStatus::Queued, 'hold_until' => null, 'hold_reason' => null])->save();

        return $this->envelopeFor($row);
    }

    /**
     * True when the row is a mail that has to wait for the quota; the row
     * is then re-held in place. Anything that is not mail, or is urgent,
     * passes.
     */
    private function reholdForQuota(MessageRecord $row): bool
    {
        if ($row->channel !== Channel::Mail || $row->urgent) {
            return false;
        }

        $scope = $this->scopes[$row->brand_id] ??= $this->quota->scopeFor($row->brand_id);
        $hold = $this->quota->holdFor($scope, $this->released[$scope->key] ?? 0);

        if ($hold === null) {
            $this->released[$scope->key] = ($this->released[$scope->key] ?? 0) + 1;

            return false;
        }

        $row->forceFill(['hold_until' => $hold->until, 'hold_reason' => MailQuota::HOLD_REASON])->save();
        $this->quota->recordHold($row->brand_id, $hold);

        return true;
    }

    /**
     * Several held messages become one digest: a fresh log row (and a fresh
     * MessageQueued — the members had theirs at accept time) whose payload
     * lists each member's subject rendered exactly as an immediate send
     * would have, beside the `url` that member's own payload carried (Task
     * 27) — null for a template that points nowhere. The digest row is the
     * one that travels, so it is the one the quota may hold: then it is
     * written `held` under `mail_quota` and a later sweep sends it as
     * itself.
     *
     * @param  list<MessageRecord>  $rows
     * @return array{0: SendMessageData|null, 1: MessageQueued}
     */
    private function coalesce(array $rows): array
    {
        $first = $rows[0];
        $digestId = (string) Str::ulid();

        $items = array_map(
            function (MessageRecord $row): DigestItemData {
                $envelope = $this->envelopeFor($row);
                // The link is the member's own, read off the payload the log
                // kept — the same key `InAppChannel` and the announcement
                // template read. A non-string or an empty value is no link.
                $url = $envelope->payload->toArray()['url'] ?? null;

                return new DigestItemData(
                    subject: $this->renderer->render($envelope)->subject,
                    url: is_string($url) && $url !== '' ? $url : null,
                );
            },
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

        $queued = new MessageQueued(
            messageId: $digestId,
            brandId: $envelope->brandId,
            recipientId: $envelope->recipientId,
            channel: $envelope->channel,
            templateKey: $envelope->templateKey,
        );

        if ($this->reholdForQuota($record->forceFill(['status' => MessageStatus::Held]))) {
            return [null, $queued];
        }

        $record->forceFill(['status' => MessageStatus::Queued])->save();

        return [$envelope, $queued];
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
