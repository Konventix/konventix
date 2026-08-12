<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Str;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\Recipient;
use Konventix\Platform\Brands\BrandContext;
use RuntimeException;

/**
 * The send API every other module calls (SPEC.md "Owns"): hand it a
 * recipient, a template key and a typed payload, get delivery semantics
 * back. Nothing about *why* a message is sent lives here.
 *
 * Resolution happens at accept time, not in the queue: the brand comes from
 * the request's explicit `brandId` or the ambient BrandContext; email and
 * locale come from explicit overrides or the recipient read model. A
 * request that resolves to no address at all is refused loudly — silence
 * is the one failure mode a messaging pipeline must not have.
 *
 * No database write happens in this Action, so there is no
 * `DB::transaction` to wrap. Channel selection is not implemented: every
 * message sends on Channel::Mail today (the only bound driver).
 */
final class SendMessage
{
    public function __construct(private readonly BrandContext $brandContext) {}

    public function handle(SendMessageRequest $request): SendMessageData
    {
        $brandId = $request->brandId ?? $this->brandContext->currentOrFail()->getKey();
        $channel = Channel::Mail;

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
