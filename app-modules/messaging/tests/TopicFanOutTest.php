<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Channels\MailChannel;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\RenderedMessage;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Spatie\LaravelData\Data;

/**
 * Topic fan-out (ADR-0041, D-MSG-05): the topic's declared channels,
 * intersected with the recipient's preferences, one envelope per channel.
 */
final class TopicFanOutTestPayload extends Data
{
    public function __construct(public readonly string $name = 'Erika') {}
}

final class TopicFanOutTestRecordingChannel implements MessageChannel
{
    /** @var list<string> */
    public static array $sent = [];

    public function send(SendMessageData $message, RenderedMessage $rendered): void
    {
        self::$sent[] = $message->messageId;
    }
}

function topicFanOutSetup(array $defaultChannels, bool $suppressible = true): string
{
    TopicFanOutTestRecordingChannel::$sent = [];

    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.fanout',
        subjects: ['en' => 'S {{ $name }}'],
        bodies: ['en' => 'B {{ $name }}'],
    ));

    app(TopicRegistry::class)->register(new Topic(
        key: 'test.topic',
        label: 'Test topic',
        defaultChannels: $defaultChannels,
        suppressible: $suppressible,
    ));

    // A second registered driver stands in for in-app until its slice
    // lands, proving multi-channel fan-out through the map.
    $map = new ChannelMap;
    $map->register(Channel::Mail, MailChannel::class);
    $map->register(Channel::InApp, TopicFanOutTestRecordingChannel::class);
    app()->instance(ChannelMap::class, $map);

    // Project a recipient so id-only sends resolve.
    $userId = (string) Str::ulid();
    event(new UserRegistered(
        userId: $userId,
        brandId: (string) Str::ulid(),
        email: 'erika@example.test',
        displayName: 'Erika',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    return $userId;
}

function topicFanOutSend(string $userId): array
{
    return app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.fanout',
        payload: new TopicFanOutTestPayload,
        topicKey: 'test.topic',
    ));
}

it('creates one envelope and one log row per topic channel', function (): void {
    $this->withBrand();
    $userId = topicFanOutSetup([Channel::Mail, Channel::InApp]);

    Mail::fake();
    Event::fake([MessageQueued::class]);

    $envelopes = topicFanOutSend($userId);

    expect(array_map(fn (SendMessageData $e): string => $e->channel, $envelopes))
        ->toBe(['mail', 'in_app'])
        ->and(MessageRecord::withoutBrandScope()->count())->toBe(2)
        ->and(TopicFanOutTestRecordingChannel::$sent)->toHaveCount(1);

    Event::assertDispatchedTimes(MessageQueued::class, 2);
});

it('refuses a preference-disabled channel visibly, not silently', function (): void {
    $this->withBrand();
    $userId = topicFanOutSetup([Channel::Mail, Channel::InApp]);

    NotificationPreference::query()->create([
        'recipient_id' => $userId,
        'topic_key' => 'test.topic',
        'channel' => Channel::Mail,
        'enabled' => false,
    ]);

    Mail::fake();
    Event::fake([MessageSuppressed::class]);

    $envelopes = topicFanOutSend($userId);

    expect(array_map(fn (SendMessageData $e): string => $e->channel, $envelopes))->toBe(['in_app']);

    $suppressed = MessageRecord::withoutBrandScope()->where('status', MessageStatus::Suppressed)->sole();
    expect($suppressed->channel)->toBe(Channel::Mail);

    Event::assertDispatched(
        MessageSuppressed::class,
        fn (MessageSuppressed $e): bool => $e->reasonCode === 'preferences' && $e->channel === 'mail',
    );
    Mail::assertNothingSent();
});

it('ignores preferences on a non-suppressible topic', function (): void {
    $this->withBrand();
    $userId = topicFanOutSetup([Channel::Mail], suppressible: false);

    NotificationPreference::query()->create([
        'recipient_id' => $userId,
        'topic_key' => 'test.topic',
        'channel' => Channel::Mail,
        'enabled' => false,
    ]);

    Mail::fake();

    $envelopes = topicFanOutSend($userId);

    expect($envelopes)->toHaveCount(1)
        ->and(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('skips channels that need an account for an account-less recipient', function (): void {
    $this->withBrand();
    topicFanOutSetup([Channel::Mail, Channel::InApp]);

    Mail::fake();

    $envelopes = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'test.fanout',
        payload: new TopicFanOutTestPayload,
        recipientEmail: 'billing@example.test',
        topicKey: 'test.topic',
    ));

    expect(array_map(fn (SendMessageData $e): string => $e->channel, $envelopes))->toBe(['mail']);
});

it('keeps the pre-topic contract for topicless sends', function (): void {
    $this->withBrand();
    $userId = topicFanOutSetup([Channel::Mail, Channel::InApp]);

    Mail::fake();

    $envelopes = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.fanout',
        payload: new TopicFanOutTestPayload,
    ));

    expect(array_map(fn (SendMessageData $e): string => $e->channel, $envelopes))->toBe(['mail']);
});
