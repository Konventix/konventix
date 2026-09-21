<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Events\NotificationRead;
use Konventix\Messaging\Events\NotificationReceived;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Models\Operator;
use Spatie\LaravelData\Data;

/**
 * The in-app inbox (ADR-0041 point 6): the channel's delivery is the
 * notification row; the bell reads JSON endpoints and hears about new rows
 * over Reverb.
 */
final class InAppInboxTestPayload extends Data
{
    public function __construct(
        public readonly string $name = 'Erika',
        public readonly ?string $url = null,
    ) {}
}

function inAppInboxSetup(): string
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.inbox',
        subjects: ['en' => 'Hello {{ $name }}'],
        bodies: ['en' => '<p>Body for {{ $name }}</p>'],
    ));

    app(TopicRegistry::class)->register(new Topic(
        key: 'test.inbox-topic',
        label: 'Inbox test topic',
        defaultChannels: [Channel::InApp],
    ));

    $userId = (string) Str::ulid();
    event(new UserRegistered(
        userId: $userId,
        brandId: (string) Str::ulid(),
        email: 'erika@example.test',
        displayName: 'Erika',
        username: 'erika',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    return $userId;
}

function inAppInboxSend(string $userId, ?string $url = null): void
{
    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.inbox',
        payload: new InAppInboxTestPayload(url: $url),
        topicKey: 'test.inbox-topic',
    ));
}

it('delivers in-app by writing the inbox row and pushing to the browser', function (): void {
    $this->withBrand();
    $userId = inAppInboxSetup();

    Event::fake([NotificationReceived::class]);

    inAppInboxSend($userId, url: 'https://example.test/somewhere');

    $row = InboxNotification::withoutBrandScope()->sole();
    expect($row->recipient_id)->toBe($userId)
        ->and($row->title)->toBe('Hello Erika')
        ->and($row->body)->toBe('<p>Body for Erika</p>')
        ->and($row->url)->toBe('https://example.test/somewhere')
        ->and($row->topic_key)->toBe('test.inbox-topic')
        ->and($row->isUnread())->toBeTrue();

    Event::assertDispatched(
        NotificationReceived::class,
        fn (NotificationReceived $event): bool => $event->notificationId === $row->id
            && $event->broadcastAs() === 'notification.received'
            && $event->broadcastOn()->name === 'private-Konventix.Identity.Models.User.'.$userId,
    );
});

it('serves the bell feed with the unread count', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    InboxNotification::factory()->count(2)->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);
    InboxNotification::factory()->read()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);
    // Another person's notification never shows.
    InboxNotification::factory()->create(['brand_id' => $brand->getKey()]);

    $this->actingAs($user)
        // The bell's endpoint moved a level deeper when `/notifications`
        // became the inbox page a person opens.
        ->getJson('/notifications/feed')
        ->assertOk()
        ->assertJsonPath('unread', 2)
        ->assertJsonCount(3, 'notifications');
});

it('serves an empty feed on the operator console host, where no brand resolves', function (): void {
    // The bell sits in the console shell too. A request to the admin host
    // resolves no brand (ADR-0029) and BrandScope hardens brand-owned
    // queries to nothing — the feed must answer with an empty inbox rather
    // than fail, or the bell stays on "Loading…" for the life of the page.
    // The row is created before the request: after it, no brand resolves.
    $brand = $this->withBrand();
    $user = User::factory()->create();
    InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);

    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.inbox.test'])->save();

    $this->actingAs($user)
        ->getJson('http://admin.inbox.test/notifications/feed')
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonCount(0, 'notifications');
});

it('marks a notification read once and emits NotificationRead', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $notification = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);

    Event::fake([NotificationRead::class]);

    $this->actingAs($user)
        ->postJson("/notifications/{$notification->getKey()}/read")
        ->assertOk();

    $readAt = $notification->refresh()->read_at;
    expect($readAt)->not->toBeNull();

    // Idempotent: a second click keeps the first read_at and stays silent.
    $this->travel(5)->minutes();
    $this->actingAs($user)
        ->postJson("/notifications/{$notification->getKey()}/read")
        ->assertOk();

    expect($notification->refresh()->read_at?->equalTo($readAt))->toBeTrue();
    Event::assertDispatchedTimes(NotificationRead::class, 1);
});

it('refuses to mark another user\'s notification read', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $foreign = InboxNotification::factory()->create(['brand_id' => $brand->getKey()]);

    $this->actingAs($user)
        ->postJson("/notifications/{$foreign->getKey()}/read")
        ->assertNotFound();

    expect($foreign->refresh()->isUnread())->toBeTrue();
});

it('marks all notifications read at once', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    InboxNotification::factory()->count(3)->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);

    Event::fake([NotificationRead::class]);

    $this->actingAs($user)
        ->postJson('/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('read', 3);

    expect(InboxNotification::withoutBrandScope()->whereNull('read_at')->where('recipient_id', (string) $user->getKey())->count())->toBe(0);
    Event::assertDispatchedTimes(NotificationRead::class, 3);
});

it('skips the in-app channel for account-less recipients, mail-only topics unaffected', function (): void {
    $this->withBrand();
    inAppInboxSetup();

    Mail::fake();

    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'test.inbox',
        payload: new InAppInboxTestPayload,
        recipientEmail: 'billing@example.test',
        topicKey: 'test.inbox-topic',
    ));

    expect(InboxNotification::withoutBrandScope()->count())->toBe(0);
});
