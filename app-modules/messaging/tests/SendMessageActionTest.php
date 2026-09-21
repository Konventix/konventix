<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Mail\MailQuota;
use Konventix\Messaging\Models\MessageRecord;
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

function messagingSendRequest(string $recipientId, bool $urgent = false): SendMessageRequest
{
    return new SendMessageRequest(
        recipientId: $recipientId,
        recipientEmail: 'ada@example.test',
        recipientLocale: 'en',
        templateKey: 'test.welcome',
        payload: new MessagingActionTestPayload('Ada'),
        urgent: $urgent,
    );
}

it('queues the job on Messaging\'s own queue with the accepted message', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $recipientId = (string) Str::ulid();
    [$message] = app(SendMessage::class)->handle(messagingSendRequest($recipientId));

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
    [$message] = app(SendMessage::class)->handle(messagingSendRequest($recipientId));

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

it('holds a mail for the quota once the hourly budget is spent, until the next hour', function (): void {
    // Task 14: the mail is accepted and logged, never dropped — it waits
    // for the window and MessageQueued still fires for it.
    $brand = $this->withBrand();
    config(['messaging.mail_quota.per_hour' => 1]);
    $this->travelTo(Carbon::parse('2026-09-21 14:20:00', 'UTC'));
    app(MailQuota::class)->record($brand->getKey());

    Queue::fake();
    Event::fake([MessageQueued::class]);

    [$message] = app(SendMessage::class)->handle(messagingSendRequest((string) Str::ulid()));

    $row = MessageRecord::withoutBrandScope()->findOrFail($message->messageId);
    expect($row->status)->toBe(MessageStatus::Held)
        ->and($row->hold_reason)->toBe('mail_quota')
        ->and($row->hold_until?->toDateTimeString())->toBe('2026-09-21 15:00:00');

    Queue::assertNothingPushed();
    Event::assertDispatched(MessageQueued::class);
});

it('never holds an urgent mail for the quota', function (): void {
    $brand = $this->withBrand();
    config(['messaging.mail_quota.per_hour' => 1]);
    app(MailQuota::class)->record($brand->getKey());

    Queue::fake();

    [$message] = app(SendMessage::class)->handle(messagingSendRequest((string) Str::ulid(), urgent: true));

    expect(MessageRecord::withoutBrandScope()->findOrFail($message->messageId)->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendQueuedMessage::class);
});
