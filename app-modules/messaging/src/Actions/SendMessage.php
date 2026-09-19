<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Channels\UndeliverableAddress;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Digests\HoldPolicy;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Models\Suppression;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Brands\BrandContext;
use RuntimeException;

/**
 * The send API every other module calls (SPEC.md "Owns"): hand it a
 * recipient, a template key and a typed payload, get delivery semantics
 * back. Nothing about *why* a message is sent lives here.
 *
 * ADR-0041 made this the fan-out point: a request naming a topic resolves
 * that topic's default channels intersected with the recipient's
 * preferences and produces one envelope — and one delivery-log row — per
 * channel. A request without a topic behaves as it always did: one
 * immediate mail envelope, no preference gate. The suppression list is
 * checked here, before dispatch; a refused channel gets a `suppressed` log
 * row and `MessageSuppressed`, never silence.
 *
 * A request that resolves to no address at all is still refused loudly —
 * silence is the one failure mode a messaging pipeline must not have.
 */
final class SendMessage
{
    public function __construct(
        private readonly BrandContext $brandContext,
        private readonly TopicRegistry $topics,
        private readonly ChannelMap $channels,
        private readonly HoldPolicy $holds,
    ) {}

    /**
     * @return list<SendMessageData> the accepted envelopes, one per channel
     *                               (suppressed channels produce no envelope)
     */
    public function handle(SendMessageRequest $request): array
    {
        $brandId = $request->brandId ?? $this->brandContext->currentOrFail()->getKey();

        $recipient = $request->recipientId === null
            ? null
            : Recipient::query()->find($request->recipientId);

        $email = $request->recipientEmail ?? $recipient?->email;
        $locale = $request->recipientLocale
            ?? ($recipient === null ? (string) config('app.locale') : $recipient->locale);

        if ($email === null) {
            throw new RuntimeException(sprintf(
                'Message [%s] has no resolvable recipient address: no explicit email, and %s.',
                $request->templateKey,
                $request->recipientId === null
                    ? 'no recipient id was given'
                    : "recipient [{$request->recipientId}] is not in the read model (registered before the projection existed?)",
            ));
        }

        $envelopes = [];

        foreach ($this->channelsFor($request) as $channel) {
            $message = new SendMessageData(
                messageId: (string) Str::ulid(),
                brandId: $brandId,
                recipientId: $request->recipientId,
                recipientEmail: $email,
                recipientLocale: $locale,
                templateKey: $request->templateKey,
                payload: $request->payload,
                channel: $channel->value,
                attachments: $request->attachments,
                topicKey: $request->topicKey,
                urgent: $request->urgent,
                heroImageUrl: $request->heroImageUrl,
                heroImageAlt: $request->heroImageAlt,
            );

            $refusal = $this->refusalFor($request, $channel, $brandId, $email);

            if ($refusal !== null) {
                $this->log($message, MessageStatus::Suppressed);

                event(new MessageSuppressed(
                    messageId: $message->messageId,
                    brandId: $brandId,
                    recipientId: $request->recipientId,
                    templateKey: $request->templateKey,
                    channel: $channel->value,
                    reasonCode: $refusal,
                ));

                continue;
            }

            // Digestable topics may hold instead of dispatching (ADR-0041
            // point 5): the row is `held` in the log — visible, never
            // swallowed — and the sweep sends or coalesces it later. The
            // message is accepted either way, so MessageQueued still fires.
            $holdUntil = $this->holds->holdUntil(
                $request,
                $request->topicKey === null ? null : $this->topics->get($request->topicKey),
                $channel,
                $brandId,
                $email,
                $recipient,
            );

            if ($holdUntil !== null) {
                $this->log($message, MessageStatus::Held, $holdUntil);
            } else {
                $this->log($message, MessageStatus::Queued);

                // A caller's own write often shares the transaction this
                // call runs inside (an order placed, a registration
                // confirmed); dispatching only after it commits means a
                // rollback sends no mail for a change that never happened
                // (ADR-0041/ADR-0048's "sends dispatch afterCommit").
                SendQueuedMessage::dispatch($message)->afterCommit();
            }

            event(new MessageQueued(
                messageId: $message->messageId,
                brandId: $message->brandId,
                recipientId: $message->recipientId,
                channel: $message->channel,
                templateKey: $message->templateKey,
            ));

            $envelopes[] = $message;
        }

        return $envelopes;
    }

    /**
     * The topic decides the channels (ADR-0041): its declared defaults,
     * minus what is structurally impossible — a channel that needs an
     * account this recipient does not have, or one no driver serves yet.
     * No topic means the pre-topic contract: exactly one mail envelope.
     * Refusals (preferences, suppression list) are NOT filtered here — they
     * produce a suppressed log row and `MessageSuppressed`, never silence.
     *
     * @return list<Channel>
     */
    private function channelsFor(SendMessageRequest $request): array
    {
        if ($request->topicKey === null) {
            return [Channel::Mail];
        }

        return array_values(array_filter(
            $this->topics->get($request->topicKey)->defaultChannels,
            fn (Channel $channel): bool => $this->channels->supports($channel)
                && ! ($channel->requiresRecipientId() && $request->recipientId === null),
        ));
    }

    /**
     * Why this channel must not deliver, or null to proceed: the recipient
     * opted out (only consulted for suppressible topics — a preference is
     * a choice, and security notices offer none), or the brand's
     * suppression list holds the address/recipient (checked for every
     * send — a hard bounce is a deliverability fact, not a choice).
     */
    private function refusalFor(SendMessageRequest $request, Channel $channel, string $brandId, string $email): ?string
    {
        if ($request->topicKey !== null
            && $request->recipientId !== null
            && $this->topics->get($request->topicKey)->suppressible
            && NotificationPreference::query()
                ->where('recipient_id', $request->recipientId)
                ->where('topic_key', $request->topicKey)
                ->where('channel', $channel)
                ->where('enabled', false)
                ->exists()) {
            return 'preferences';
        }

        if (Suppression::blocks($brandId, $channel, $email, $request->recipientId)) {
            return 'suppression_list';
        }

        // A reserved-TLD address (#293) is refused on the mail channel only:
        // the in-app row and a push subscription reach the account, not the
        // address. Logged as suppressed so the delivery log shows the
        // attempt instead of the transport quietly eating it.
        if ($channel === Channel::Mail && UndeliverableAddress::is($email)) {
            return 'undeliverable_address';
        }

        return null;
    }

    private function log(SendMessageData $message, MessageStatus $status, ?CarbonImmutable $holdUntil = null): void
    {
        $record = new MessageRecord([
            'id' => $message->messageId,
            'recipient_id' => $message->recipientId,
            'recipient_email' => $message->recipientEmail,
            'recipient_locale' => $message->recipientLocale,
            'channel' => $message->channel,
            'template_key' => $message->templateKey,
            'topic_key' => $message->topicKey,
            'status' => $status,
            'payload' => $message->payload->toArray(),
            // Referenced, not inlined (AttachmentData's own docblock) — a
            // resend re-reads these from disk rather than the row carrying
            // bytes (Actions\ResendMessage).
            'attachments' => $message->attachments?->toArray(),
            'urgent' => $message->urgent,
            'hold_until' => $holdUntil,
            'queued_at' => now(),
        ]);
        // brand_id from the envelope, not ambient context (ADR-0010):
        // operator-console and queue-triggered sends carry their own.
        $record->forceFill(['brand_id' => $message->brandId])->save();
    }
}
