<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\TopicAudience;
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

/**
 * An operator-audience topic (Task 9 of the 2026-09-21 bugfix round): its
 * rows belong to the console bell on the admin host, never to the
 * participant inbox on a brand host.
 */
function inAppInboxOperatorTopic(): string
{
    app(TopicRegistry::class)->register(new Topic(
        key: 'test.inbox-operator-topic',
        label: 'Inbox operator test topic',
        defaultChannels: [Channel::InApp],
        audience: TopicAudience::Operator,
    ));

    return 'test.inbox-operator-topic';
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

it('keeps operator notices out of the participant inbox on a brand host', function (): void {
    // An organizer who is also a registrant: the console notice addressed
    // to them must not show up beside their participant notifications on
    // the brand host — neither in the bell's feed nor on the inbox page.
    $brand = $this->withBrand();
    $topic = inAppInboxOperatorTopic();
    $user = User::factory()->create();

    $participant = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);
    InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'topic_key' => $topic,
        'url' => 'https://admin.inbox.test/admin/orders/1',
    ]);

    $this->actingAs($user)
        ->getJson('/notifications/feed')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $participant->getKey());

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/Index')
            ->where('unread', 1)
            ->count('notifications.data', 1)
            ->where('notifications.data.0.id', $participant->getKey()));
});

it('serves the operator notices on the console host, and none of the participant inbox', function (): void {
    // The bell sits in the console shell too. A request to the admin host
    // resolves no brand (ADR-0029) and BrandScope hardens brand-owned
    // queries to nothing, so the feed reads the operator-audience rows
    // through `withoutBrandScope()` — the signed-in recipient's own, across
    // the operator's brands. The rows are created before the request:
    // after it, no brand resolves.
    $brand = $this->withBrand();
    $topic = inAppInboxOperatorTopic();
    $user = User::factory()->create();

    InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);
    $notice = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'topic_key' => $topic,
        'url' => 'https://admin.inbox.test/admin/orders/1',
    ]);
    // Another operator account's notice never shows.
    InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'topic_key' => $topic,
    ]);

    $brand->operator->forceFill(['admin_host' => 'admin.inbox.test'])->save();

    $this->actingAs($user)
        ->getJson('http://admin.inbox.test/notifications/feed')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $notice->getKey())
        ->assertJsonPath('notifications.0.url', 'https://admin.inbox.test/admin/orders/1');

    $this->actingAs($user)
        ->get('http://admin.inbox.test/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/Index')
            ->where('unread', 1)
            ->count('notifications.data', 1)
            ->where('notifications.data.0.id', $notice->getKey()));
});

it('never serves another operator\'s notices on its console host', function (): void {
    $brand = $this->withBrand();
    $topic = inAppInboxOperatorTopic();
    $user = User::factory()->create();

    InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'topic_key' => $topic,
    ]);

    // A second operator, owning none of this brand — its console must not
    // list the notice however the recipient is addressed there
    // (`operators.admin_host` is unique, .ai/rules/tests.md).
    Operator::factory()->create(['admin_host' => 'admin.other-operator.test']);

    $this->actingAs($user)
        ->getJson('http://admin.other-operator.test/notifications/feed')
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonCount(0, 'notifications');
});

it('marks read only what the host serves', function (): void {
    $brand = $this->withBrand();
    $topic = inAppInboxOperatorTopic();
    $user = User::factory()->create();

    $participant = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);
    $notice = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'topic_key' => $topic,
    ]);

    $brand->operator->forceFill(['admin_host' => 'admin.inbox.test'])->save();

    // An operator notice is not readable from the brand host: the feed
    // never listed it there, so the read endpoint does not know it either.
    $this->actingAs($user)
        ->postJson("/notifications/{$notice->getKey()}/read")
        ->assertNotFound();

    // "Mark all" on the console host reads the console's rows and leaves
    // the participant inbox untouched.
    $this->actingAs($user)
        ->postJson('http://admin.inbox.test/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('read', 1);

    expect($notice->refresh()->isUnread())->toBeFalse()
        ->and($participant->refresh()->isUnread())->toBeTrue();
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
