<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Channels\PushDeliveryReport;
use Konventix\Messaging\Contracts\PushSender;
use Konventix\Messaging\Data\PushMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\PushSubscription;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Spatie\LaravelData\Data;

/**
 * The web-push channel (ADR-0041 point 7, D-MSG-02): typed payload out,
 * one send per subscribed device, gone endpoints pruned, no devices no
 * failure.
 */
final class PushChannelTestPayload extends Data
{
    public function __construct(
        public readonly string $name = 'Erika',
        public readonly ?string $url = null,
    ) {}
}

final class PushChannelTestFakeSender implements PushSender
{
    /** @var list<array{endpoint: string, message: PushMessageData}> */
    public array $sent = [];

    /** @var array<string, PushDeliveryReport> keyed by endpoint */
    public array $reports = [];

    public function send(PushSubscription $subscription, PushMessageData $message): PushDeliveryReport
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'message' => $message];

        return $this->reports[$subscription->endpoint]
            ?? new PushDeliveryReport(success: true, expired: false);
    }
}

function pushChannelSetup(): array
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.push',
        subjects: ['en' => 'Ping {{ $name }}'],
        bodies: ['en' => '<p>Push body for {{ $name }}</p>'],
    ));

    app(TopicRegistry::class)->register(new Topic(
        key: 'test.push-topic',
        label: 'Push test topic',
        defaultChannels: [Channel::Push],
    ));

    $sender = new PushChannelTestFakeSender;
    app()->instance(PushSender::class, $sender);

    $userId = (string) Str::ulid();
    event(new UserRegistered(
        userId: $userId,
        brandId: (string) Str::ulid(),
        email: 'erika@example.test',
        displayName: 'Erika',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    return [$userId, $sender];
}

function pushChannelSend(string $userId, ?string $url = null): void
{
    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.push',
        payload: new PushChannelTestPayload(url: $url),
        topicKey: 'test.push-topic',
    ));
}

it('delivers the typed payload to every subscribed device', function (): void {
    $this->withBrand();
    [$userId, $sender] = pushChannelSetup();

    PushSubscription::factory()->count(2)->create(['recipient_id' => $userId]);

    pushChannelSend($userId, url: 'https://example.test/target');

    expect($sender->sent)->toHaveCount(2);

    $message = $sender->sent[0]['message'];
    expect($message->title)->toBe('Ping Erika')
        ->and($message->body)->toBe('Push body for Erika')
        ->and($message->url)->toBe('https://example.test/target')
        ->and($message->tag)->toBe('test.push-topic')
        ->and(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('prunes a subscription the push service reports gone', function (): void {
    $this->withBrand();
    [$userId, $sender] = pushChannelSetup();

    $kept = PushSubscription::factory()->create(['recipient_id' => $userId]);
    $gone = PushSubscription::factory()->create(['recipient_id' => $userId]);
    $sender->reports[$gone->endpoint] = new PushDeliveryReport(success: false, expired: true);

    pushChannelSend($userId);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('treats a recipient with no devices as a no-op, not a failure', function (): void {
    $this->withBrand();
    [$userId, $sender] = pushChannelSetup();

    pushChannelSend($userId);

    expect($sender->sent)->toBe([])
        ->and(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('fails the send when every endpoint refuses', function (): void {
    $this->withBrand();
    [$userId, $sender] = pushChannelSetup();

    $subscription = PushSubscription::factory()->create(['recipient_id' => $userId]);
    $sender->reports[$subscription->endpoint] = new PushDeliveryReport(
        success: false,
        expired: false,
        reason: 'internal error at the push service',
    );

    expect(fn () => pushChannelSend($userId))->toThrow(RuntimeException::class);

    // The endpoint stays: a transient refusal is not a gone device.
    expect(PushSubscription::query()->count())->toBe(1);
});

it('stores a device subscription once per endpoint, refreshing keys', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $payload = [
        'endpoint' => 'https://push.example.test/send/abc123',
        'p256dh' => base64_encode(random_bytes(65)),
        'auth' => base64_encode(random_bytes(16)),
    ];

    $this->actingAs($user)->postJson('/push-subscriptions', $payload)->assertCreated();

    $updated = [...$payload, 'p256dh' => base64_encode(random_bytes(65))];
    $this->actingAs($user)->postJson('/push-subscriptions', $updated)->assertCreated();

    $rows = PushSubscription::query()->where('recipient_id', (string) $user->getKey())->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->p256dh)->toBe($updated['p256dh']);
});

it('removes a device subscription by endpoint', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $subscription = PushSubscription::factory()->create([
        'recipient_id' => (string) $user->getKey(),
    ]);
    // Another user's row with a different endpoint is untouched.
    $foreign = PushSubscription::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/push-subscriptions', ['endpoint' => $subscription->endpoint])
        ->assertOk();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$foreign->id]);
});

it('requires authentication for subscription management', function (): void {
    $this->withBrand();

    $this->postJson('/push-subscriptions', [])->assertUnauthorized();
});
