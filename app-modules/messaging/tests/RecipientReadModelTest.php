<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\Recipient;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;

final class RecipientTestPayload extends Data
{
    public function __construct(public readonly string $displayName) {}
}

function registeredEventFor(string $userId, string $brandId, string $email = 'ada@example.test'): UserRegistered
{
    return new UserRegistered(
        userId: $userId,
        brandId: $brandId,
        email: $email,
        displayName: 'Ada Lovelace',
        username: 'ada-lovelace',
        locale: 'de',
        registeredAt: now()->toIso8601String(),
    );
}

it('projects UserRegistered into the recipient read model, idempotently', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(registeredEventFor($userId, $brand->getKey()));
    event(registeredEventFor($userId, $brand->getKey()));

    $recipient = Recipient::query()->findOrFail($userId);

    expect(Recipient::query()->count())->toBe(1)
        ->and($recipient->email)->toBe('ada@example.test')
        ->and($recipient->display_name)->toBe('Ada Lovelace')
        ->and($recipient->locale)->toBe('de');
});

it('re-projects UserProfileUpdated over an existing row', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(registeredEventFor($userId, $brand->getKey()));

    event(new UserProfileUpdated(
        userId: $userId,
        email: 'countess@example.test',
        displayName: 'Countess of Lovelace',
        username: 'countess-lovelace',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: false,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    $recipient = Recipient::query()->findOrFail($userId);

    expect($recipient->email)->toBe('countess@example.test')
        ->and($recipient->locale)->toBe('en');
});

it('resolves email and locale from the read model when the caller passes only the recipient id', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $userId = (string) Str::ulid();
    event(registeredEventFor($userId, $brand->getKey(), 'resolved@example.test'));

    [$message] = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $userId,
        templateKey: 'test.read-model',
        payload: new RecipientTestPayload('Ada'),
    ));

    expect($message->recipientEmail)->toBe('resolved@example.test')
        ->and($message->recipientLocale)->toBe('de');

    Queue::assertPushed(SendQueuedMessage::class);
});

it('refuses a send that resolves to no address at all', function (): void {
    $this->withBrand();
    Queue::fake();

    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: (string) Str::ulid(),
        templateKey: 'test.read-model',
        payload: new RecipientTestPayload('Ada'),
    ));
})->throws(RuntimeException::class, 'no resolvable recipient address');

it('accepts an explicit brand override outside any brand context', function (): void {
    // Deliberately no withBrand(): the caller names the brand, as a queue
    // worker or operator-console trigger would.
    $brand = Brand::factory()->create();
    Queue::fake();

    [$message] = app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'test.read-model',
        payload: new RecipientTestPayload('Ada'),
        recipientEmail: 'billing@example.test',
        brandId: $brand->getKey(),
    ));

    expect($message->brandId)->toBe($brand->getKey())
        ->and($message->recipientId)->toBeNull()
        ->and($message->recipientEmail)->toBe('billing@example.test');
});
