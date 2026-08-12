<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Str;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Brands\BrandContext;

/**
 * The send API every other module calls (SPEC.md "Owns"; TODO.md Next item
 * 1): hand it a recipient ULID, a template key and a typed payload, get
 * delivery semantics back. Nothing about *why* a message is sent lives here.
 *
 * No database write happens in this Action, so there is no `DB::transaction`
 * to wrap — accepting a message means generating its id, dispatching the
 * queued job, and emitting `MessageQueued`. Persisting an attempt is the
 * delivery log's job (SPEC.md Backlog).
 *
 * Channel selection is not implemented: every message sends on Channel::Mail
 * today, because mail is the only driver that exists (TODO.md Next item 1).
 * `SendMessageRequest` gains a channel parameter once in-app/push land.
 */
final class SendMessage
{
    public function __construct(private readonly BrandContext $brandContext) {}

    public function handle(SendMessageRequest $request): SendMessageData
    {
        $brand = $this->brandContext->currentOrFail();
        $channel = Channel::Mail;

        $message = new SendMessageData(
            messageId: (string) Str::ulid(),
            brandId: $brand->getKey(),
            recipientId: $request->recipientId,
            recipientEmail: $request->recipientEmail,
            recipientLocale: $request->recipientLocale,
            templateKey: $request->templateKey,
            payload: $request->payload,
            channel: $channel->value,
        );

        SendQueuedMessage::dispatch($message);

        event(new MessageQueued(
            messageId: $message->messageId,
            brandId: $message->brandId,
            recipientId: $message->recipientId,
            channel: $message->channel,
            templateKey: $message->templateKey,
        ));

        return $message;
    }
}
