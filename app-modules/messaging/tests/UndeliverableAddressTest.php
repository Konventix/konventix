<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Channels\UndeliverableAddress;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Spatie\LaravelData\Data;

/**
 * #293: a reserved-TLD address (RFC 2606 `.invalid`) is refused on the mail
 * channel and logged as suppressed, while the in-app channel — which reaches
 * the account, not the address — still delivers. `undeliverable*` helper
 * names are scoped to this file (.ai/rules/tests.md).
 */
final class UndeliverableAddressTestPayload extends Data
{
    public function __construct(public readonly string $name = 'Erika') {}
}

function undeliverableSetup(): void
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.undeliverable',
        subjects: ['en' => 'Hello {{ $name }}'],
        bodies: ['en' => '<p>Hi {{ $name }}</p>'],
    ));

    app(TopicRegistry::class)->register(new Topic(
        key: 'test.undeliverable-both',
        label: 'Mail and in-app',
        defaultChannels: [Channel::Mail, Channel::InApp],
        suppressible: false,
    ));
}

it('recognises the reserved TLD and nothing else', function (): void {
    expect(UndeliverableAddress::is('steam-1@pending.invalid'))->toBeTrue()
        ->and(UndeliverableAddress::is('x@INVALID'))->toBeTrue()
        ->and(UndeliverableAddress::is('x@Sub.Example.Invalid'))->toBeTrue()
        ->and(UndeliverableAddress::is('erika@example.test'))->toBeFalse()
        ->and(UndeliverableAddress::is('invalid@example.com'))->toBeFalse()
        ->and(UndeliverableAddress::is('x@invalid.example.com'))->toBeFalse();
});

it('refuses the mail channel for a reserved-TLD address and logs the refusal', function (): void {
    $this->withBrand();
    undeliverableSetup();
    Queue::fake();
    Event::fake([MessageSuppressed::class]);

    $envelopes = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'test.undeliverable',
        payload: new UndeliverableAddressTestPayload,
        recipientEmail: 'steam-76561198000000293@pending.invalid',
    ));

    expect($envelopes)->toBe([]);
    Queue::assertNotPushed(SendQueuedMessage::class);

    $record = MessageRecord::query()->where('template_key', 'test.undeliverable')->sole();
    expect($record->status)->toBe(MessageStatus::Suppressed)
        ->and($record->channel)->toBe(Channel::Mail);

    Event::assertDispatched(
        MessageSuppressed::class,
        fn (MessageSuppressed $event): bool => $event->reasonCode === 'undeliverable_address'
            && $event->channel === Channel::Mail->value,
    );
});

it('still delivers in-app to an account whose address is a reserved-TLD placeholder', function (): void {
    $brand = $this->withBrand();
    undeliverableSetup();

    $userId = (string) Str::ulid();
    event(new UserRegistered(
        userId: $userId,
        brandId: $brand->getKey(),
        email: 'steam-76561198000000293@pending.invalid',
        displayName: 'Erika',
        username: 'erika',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    $envelopes = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.undeliverable',
        payload: new UndeliverableAddressTestPayload,
        topicKey: 'test.undeliverable-both',
    ));

    expect($envelopes)->toHaveCount(1)
        ->and($envelopes[0]->channel)->toBe(Channel::InApp->value);

    expect(InboxNotification::withoutBrandScope()->where('recipient_id', $userId)->count())->toBe(1);

    $mail = MessageRecord::query()
        ->where('template_key', 'test.undeliverable')
        ->where('channel', Channel::Mail)
        ->sole();
    expect($mail->status)->toBe(MessageStatus::Suppressed);
});
