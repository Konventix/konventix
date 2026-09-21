<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Actions\FlushHeldMessages;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Channels\InAppChannel;
use Konventix\Messaging\Data\DigestData;
use Konventix\Messaging\Data\DigestItemData;
use Konventix\Messaging\Data\RehydratedPayload;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Digests\HoldPolicy;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Events\NotificationReceived;
use Konventix\Messaging\Mail\MailQuota;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\RenderedMessage;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Spatie\LaravelData\Data;

/**
 * Digests and quiet hours (ADR-0041 point 5): a digestable topic's send is
 * held, several holds coalesce into one digest, quiet hours defer to 08:00,
 * urgent bypasses everything — and nothing is ever dropped silently.
 */
final class DigestQuietHoursTestPayload extends Data
{
    public function __construct(
        public readonly string $name = 'Erika',
        public readonly ?string $url = null,
    ) {}
}

function digestQuietHoursSetup(bool $digestable = true): string
{
    app(TemplateRegistry::class)->register(new MessageTemplate(
        key: 'test.digest',
        subjects: ['en' => 'About {{ $name }}'],
        bodies: ['en' => 'B {{ $name }}'],
    ));

    app(TopicRegistry::class)->register(new Topic(
        key: 'test.digest-topic',
        label: 'Digest test topic',
        defaultChannels: [Channel::Mail],
        digestable: $digestable,
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

function digestQuietHoursSend(string $userId, bool $urgent = false, string $name = 'Erika', ?string $url = null): void
{
    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.digest',
        payload: new DigestQuietHoursTestPayload($name, $url),
        topicKey: 'test.digest-topic',
        urgent: $urgent,
    ));
}

it('holds a digestable topic send instead of dispatching it', function (): void {
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();
    Event::fake([MessageQueued::class]);

    digestQuietHoursSend($userId);

    $row = MessageRecord::withoutBrandScope()->sole();
    expect($row->status)->toBe(MessageStatus::Held)
        ->and($row->hold_until?->toDateTimeString())->toBe('2026-08-17 12:30:00');

    // Held is accepted, not refused: the caller's contract event fires,
    // the mail just travels later.
    Event::assertDispatched(MessageQueued::class);
    Mail::assertNothingSent();
});

it('joins an open window so a burst coalesces into one digest', function (): void {
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();

    digestQuietHoursSend($userId, name: 'One');

    $this->travelTo(Carbon::parse('2026-08-17 12:20:00', 'UTC'));
    digestQuietHoursSend($userId, name: 'Two');

    expect(
        MessageRecord::withoutBrandScope()
            ->where('status', MessageStatus::Held)
            ->pluck('hold_until')
            ->map(fn (mixed $at): string => Carbon::parse($at)->toDateTimeString())
            ->unique()
            ->all(),
    )->toBe(['2026-08-17 12:30:00']);
});

it('sends an urgent message immediately, bypassing the digest window', function (): void {
    $this->withBrand();
    $userId = digestQuietHoursSetup();

    Mail::fake();

    digestQuietHoursSend($userId, urgent: true);

    expect(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('defers a hold ending in quiet hours to 08:00 in the recipient timezone', function (): void {
    $this->withBrand();
    // 21:45 Europe/Berlin (CEST, UTC+2): the 30-minute window would end
    // 22:15 local — inside quiet hours, so it defers to 08:00 next day.
    $this->travelTo(Carbon::parse('2026-08-17 19:45:00', 'UTC'));
    $userId = digestQuietHoursSetup();
    Recipient::query()->whereKey($userId)->update(['timezone' => 'Europe/Berlin']);

    Mail::fake();

    digestQuietHoursSend($userId);

    expect(MessageRecord::withoutBrandScope()->sole()->hold_until?->toDateTimeString())
        ->toBe('2026-08-18 06:00:00'); // 08:00 Berlin in UTC
});

it('falls back to the brand timezone for recipients without one', function (): void {
    $brand = $this->withBrand();
    BrandMailSettings::factory()->create([
        'brand_id' => $brand->getKey(),
        'timezone' => 'Europe/Berlin',
    ]);

    $this->travelTo(Carbon::parse('2026-08-17 19:45:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();

    digestQuietHoursSend($userId);

    expect(MessageRecord::withoutBrandScope()->sole()->hold_until?->toDateTimeString())
        ->toBe('2026-08-18 06:00:00');
});

it('flushes a single due hold as the message itself', function (): void {
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();

    digestQuietHoursSend($userId);

    $this->travelTo(Carbon::parse('2026-08-17 12:31:00', 'UTC'));
    $result = app(FlushHeldMessages::class)->handle();

    $row = MessageRecord::withoutBrandScope()->sole();
    expect($result)->toBe(['flushed' => 1, 'digests' => 0, 'reheld' => 0])
        ->and($row->status)->toBe(MessageStatus::Sent)
        ->and($row->digest_message_id)->toBeNull()
        ->and($row->template_key)->toBe('test.digest');
});

it('coalesces several due holds into one digest in the recipient locale', function (): void {
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();
    Event::fake([MessageQueued::class]);

    digestQuietHoursSend($userId, name: 'One');
    digestQuietHoursSend($userId, name: 'Two');

    $this->travelTo(Carbon::parse('2026-08-17 12:31:00', 'UTC'));
    $result = app(FlushHeldMessages::class)->handle();

    expect($result)->toBe(['flushed' => 1, 'digests' => 1, 'reheld' => 0]);

    $digest = MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->sole();
    // `toEqual`, not `toBe`: the payload comes back out of a jsonb column,
    // which does not keep the key order it went in with.
    expect($digest->status)->toBe(MessageStatus::Sent)
        ->and($digest->payload)->toEqual(['count' => 2, 'items' => [
            ['subject' => 'About One', 'url' => null],
            ['subject' => 'About Two', 'url' => null],
        ]])
        ->and($digest->recipient_locale)->toBe('en');

    $members = MessageRecord::withoutBrandScope()->where('template_key', 'test.digest')->get();
    expect($members)->toHaveCount(2)
        ->and($members->pluck('digest_message_id')->unique()->all())->toBe([$digest->id])
        ->and($members->pluck('status')->unique()->all())->toBe([MessageStatus::Sent]);

    // The digest is a new acceptance; its members had theirs already.
    Event::assertDispatchedTimes(MessageQueued::class, 3);
});

it('carries each member\'s own link into the digest and renders it as a link in both languages', function (): void {
    // Round 4 Task 27 (D-MSG-11): a digested announcement used to arrive as
    // a bare title, while the same message sent urgently, or read in the
    // inbox, carried its `/news/{slug}` link.
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup();

    Mail::fake();

    digestQuietHoursSend($userId, name: 'One', url: 'https://sxlan.example/news/one');
    digestQuietHoursSend($userId, name: 'Two');

    $this->travelTo(Carbon::parse('2026-08-17 12:31:00', 'UTC'));
    expect(app(FlushHeldMessages::class)->handle())->toBe(['flushed' => 1, 'digests' => 1, 'reheld' => 0]);

    $digest = MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->sole();

    // A member whose payload had no `url` keeps a null one; the item shape
    // is `{subject, url}` either way.
    expect($digest->payload['items'])->toEqual([
        ['subject' => 'About One', 'url' => 'https://sxlan.example/news/one'],
        ['subject' => 'About Two', 'url' => null],
    ]);

    // Both shipped locales link the subject where there is a URL and print
    // it as text where there is none — the template is the same in each.
    foreach (['de', 'en'] as $locale) {
        $rendered = app(TemplateRenderer::class)->render(new SendMessageData(
            messageId: $digest->id,
            brandId: $digest->brand_id,
            recipientId: $digest->recipient_id,
            recipientEmail: (string) $digest->recipient_email,
            recipientLocale: $locale,
            templateKey: 'messaging.digest',
            payload: new RehydratedPayload($digest->payload),
            channel: Channel::Mail->value,
        ));

        expect($rendered->locale)->toBe($locale)
            ->and($rendered->body)->toContain('<a href="https://sxlan.example/news/one">About One</a>')
            ->and($rendered->body)->toContain('About Two')
            ->and($rendered->body)->not->toContain('<a href="">About Two</a>');
    }
});

it('gives a digest in-app row no single action url', function (): void {
    // A digest points at several things at once, so the row's action URL
    // stays null and the links live per item in the body (Task 27).
    $brand = $this->withBrand();
    Event::fake([NotificationReceived::class]);

    app(InAppChannel::class)->send(
        new SendMessageData(
            messageId: (string) Str::ulid(),
            brandId: $brand->getKey(),
            recipientId: (string) Str::ulid(),
            recipientEmail: 'erika@example.test',
            recipientLocale: 'en',
            templateKey: 'messaging.digest',
            payload: new DigestData(count: 1, items: [
                new DigestItemData(subject: 'About One', url: 'https://sxlan.example/news/one'),
            ]),
            channel: Channel::InApp->value,
        ),
        new RenderedMessage(
            subject: '1 new notification',
            body: '<ul><li><a href="https://sxlan.example/news/one">About One</a></li></ul>',
            locale: 'en',
        ),
    );

    $notification = InboxNotification::withoutBrandScope()->sole();
    expect($notification->url)->toBeNull()
        ->and($notification->body)->toContain('https://sxlan.example/news/one');
});

it('does not hold sends on a non-digestable topic', function (): void {
    $this->withBrand();
    $userId = digestQuietHoursSetup(digestable: false);

    Mail::fake();

    digestQuietHoursSend($userId);

    expect(MessageRecord::withoutBrandScope()->sole()->status)->toBe(MessageStatus::Sent);
});

it('keeps holds in separate windows per recipient', function (): void {
    $this->withBrand();
    $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
    $first = digestQuietHoursSetup();

    $second = (string) Str::ulid();
    event(new UserRegistered(
        userId: $second,
        brandId: (string) Str::ulid(),
        email: 'max@example.test',
        displayName: 'Max',
        username: 'max',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    Mail::fake();

    digestQuietHoursSend($first, name: 'One');
    digestQuietHoursSend($second, name: 'Two');

    $this->travelTo(Carbon::parse('2026-08-17 12:31:00', 'UTC'));
    $result = app(FlushHeldMessages::class)->handle();

    // Two separate recipients never share a digest: each flushes alone.
    expect($result)->toBe(['flushed' => 2, 'digests' => 0, 'reheld' => 0])
        ->and(MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->count())->toBe(0);
});

it('documents the digest window and quiet hours defaults', function (): void {
    expect(HoldPolicy::WINDOW_MINUTES)->toBe(30)
        ->and(HoldPolicy::QUIET_START_HOUR)->toBe(22)
        ->and(HoldPolicy::QUIET_END_HOUR)->toBe(8);
});

it('re-holds a quota hold at flush time when the new window is already spent', function (): void {
    // Task 14: the sweep asks the quota again before releasing, so a
    // window that filled up meanwhile re-holds instead of bursting.
    $brand = $this->withBrand();
    config(['messaging.mail_quota.per_hour' => 1]);
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup(digestable: false);

    Mail::fake();

    app(MailQuota::class)->record($brand->getKey());
    digestQuietHoursSend($userId);

    $row = MessageRecord::withoutBrandScope()->sole();
    expect($row->status)->toBe(MessageStatus::Held)
        ->and($row->hold_reason)->toBe('mail_quota')
        ->and($row->hold_until?->toDateTimeString())->toBe('2026-09-21 13:00:00');

    // Something else used the 13:00 hour before the sweep got there.
    $this->travelTo(Carbon::parse('2026-09-21 13:01:00', 'UTC'));
    app(MailQuota::class)->record($brand->getKey());

    expect(app(FlushHeldMessages::class)->handle())->toBe(['flushed' => 0, 'digests' => 0, 'reheld' => 1]);

    $row->refresh();
    expect($row->status)->toBe(MessageStatus::Held)
        ->and($row->hold_reason)->toBe('mail_quota')
        ->and($row->hold_until?->toDateTimeString())->toBe('2026-09-21 14:00:00');
    Mail::assertNothingSent();

    $this->travelTo(Carbon::parse('2026-09-21 14:00:00', 'UTC'));
    expect(app(FlushHeldMessages::class)->handle())->toBe(['flushed' => 1, 'digests' => 0, 'reheld' => 0]);
    expect($row->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($row->hold_reason)->toBeNull();
});

it('releases quota holds as themselves, never coalesced, and counts its own releases', function (): void {
    $brand = $this->withBrand();
    config(['messaging.mail_quota.per_hour' => 1]);
    $this->travelTo(Carbon::parse('2026-09-21 12:00:00', 'UTC'));
    $userId = digestQuietHoursSetup(digestable: false);

    Mail::fake();

    app(MailQuota::class)->record($brand->getKey());
    digestQuietHoursSend($userId, name: 'One');
    digestQuietHoursSend($userId, name: 'Two');

    expect(MessageRecord::withoutBrandScope()->where('hold_reason', 'mail_quota')->count())->toBe(2);

    // A fresh hour with room for exactly one: the first goes out as itself,
    // the second waits for the next hour — no digest, and no burst on a
    // counter the queued job has not moved yet.
    $this->travelTo(Carbon::parse('2026-09-21 13:00:00', 'UTC'));
    expect(app(FlushHeldMessages::class)->handle())->toBe(['flushed' => 1, 'digests' => 0, 'reheld' => 1]);

    $rows = MessageRecord::withoutBrandScope()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe(MessageStatus::Sent)
        ->and($rows[0]->template_key)->toBe('test.digest')
        ->and($rows[1]->status)->toBe(MessageStatus::Held)
        ->and($rows[1]->hold_until?->toDateTimeString())->toBe('2026-09-21 14:00:00')
        ->and(MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->count())->toBe(0);
});
