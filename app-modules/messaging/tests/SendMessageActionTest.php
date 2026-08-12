<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Spatie\LaravelData\Data;

/**
 * Covers TODO.md Next item 1 (the send API: recipient ULID, template key,
 * typed payload) and item 4's acceptance step (MessageQueued, dispatch onto
 * the `messaging` queue). Rendering/delivery is exercised in
 * SendQueuedMessageJobTest; this file is about what happens when a message is
 * *accepted*, before the job ever runs.
 */
final class MessagingActionTestPayload extends Data
{
    public function __construct(public readonly string $displayName) {}
}

function messagingSendRequest(string $recipientId): SendMessageRequest
{
    return new SendMessageRequest(
        recipientId: $recipientId,
        recipientEmail: 'ada@example.test',
        recipientLocale: 'en',
        templateKey: 'test.welcome',
        payload: new MessagingActionTestPayload('Ada'),
    );
}

it('queues the job on Messaging\'s own queue with the accepted message', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $recipientId = (string) Str::ulid();
    $message = app(SendMessage::class)->handle(messagingSendRequest($recipientId));

    expect($message->brandId)->toBe($brand->getKey())
        ->and($message->recipientId)->toBe($recipientId)
        ->and($message->channel)->toBe(Channel::Mail->value);

    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($message): bool {
        return $job->message->messageId === $message->messageId
            && $job->message->brandId === $message->brandId
            && $job->message->recipientId === $message->recipientId
            && $job->message->templateKey === $message->templateKey
            && $job->message->channel === $message->channel;
    });
});

it('emits MessageQueued with a scalar-only payload when a message is accepted', function (): void {
    $brand = $this->withBrand();
    Queue::fake();
    Event::fake([MessageQueued::class]);

    $recipientId = (string) Str::ulid();
    $message = app(SendMessage::class)->handle(messagingSendRequest($recipientId));

    Event::assertDispatched(
        MessageQueued::class,
        fn (MessageQueued $event): bool => $event->messageId === $message->messageId
            && $event->brandId === $brand->getKey()
            && $event->recipientId === $recipientId
            && $event->channel === 'mail'
            && $event->templateKey === 'test.welcome'
    );
});

it('requires the payload to be a Data object, not a free-form array', function (): void {
    expect(fn () => new SendMessageRequest(
        recipientId: (string) Str::ulid(),
        recipientEmail: 'ada@example.test',
        recipientLocale: 'en',
        templateKey: 'test.welcome',
        // @phpstan-ignore-next-line intentional contract violation under test
        payload: ['displayName' => 'Ada'],
    ))->toThrow(TypeError::class);
});
