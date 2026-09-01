<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Actions\FlushHeldMessages;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Digests\HoldPolicy;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageQueued;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
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
    public function __construct(public readonly string $name = 'Erika') {}
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

function digestQuietHoursSend(string $userId, bool $urgent = false, string $name = 'Erika'): void
{
    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.digest',
        payload: new DigestQuietHoursTestPayload($name),
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
    expect($result)->toBe(['flushed' => 1, 'digests' => 0])
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

    expect($result)->toBe(['flushed' => 1, 'digests' => 1]);

    $digest = MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->sole();
    expect($digest->status)->toBe(MessageStatus::Sent)
        ->and($digest->payload)->toBe(['count' => 2, 'items' => ['About One', 'About Two']])
        ->and($digest->recipient_locale)->toBe('en');

    $members = MessageRecord::withoutBrandScope()->where('template_key', 'test.digest')->get();
    expect($members)->toHaveCount(2)
        ->and($members->pluck('digest_message_id')->unique()->all())->toBe([$digest->id])
        ->and($members->pluck('status')->unique()->all())->toBe([MessageStatus::Sent]);

    // The digest is a new acceptance; its members had theirs already.
    Event::assertDispatchedTimes(MessageQueued::class, 3);
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
    expect($result)->toBe(['flushed' => 2, 'digests' => 0])
        ->and(MessageRecord::withoutBrandScope()->where('template_key', 'messaging.digest')->count())->toBe(0);
});

it('documents the digest window and quiet hours defaults', function (): void {
    expect(HoldPolicy::WINDOW_MINUTES)->toBe(30)
        ->and(HoldPolicy::QUIET_START_HOUR)->toBe(22)
        ->and(HoldPolicy::QUIET_END_HOUR)->toBe(8);
});
