<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AnnouncementData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * ADR-0068 §5: a mail for a person who never chose a locale renders in
 * the brand's, resolved at send time — not the config default the read
 * model used to carry.
 */
function sendMessageLocaleGermanBrand(): Brand
{
    $operator = Operator::factory()->create(['locale' => 'en']);

    return Brand::factory()->create(['operator_id' => $operator->id, 'locale' => 'de']);
}

it('renders in the brand locale for a recipient with no locale of their own', function (): void {
    Queue::fake();
    $brand = $this->withBrand(sendMessageLocaleGermanBrand());
    $user = User::factory()->create(['locale' => null]);

    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $user->id,
        templateKey: 'messaging.announcement',
        payload: new AnnouncementData(title: 'Hallo'),
        recipientEmail: $user->email,
        recipientLocale: null,
        brandId: $brand->id,
    ));

    expect(MessageRecord::query()->latest()->first()?->recipient_locale)->toBe('de');
});

it('keeps the locale the person chose over the brand locale', function (): void {
    Queue::fake();
    $brand = $this->withBrand(sendMessageLocaleGermanBrand());
    $user = User::factory()->create(['locale' => 'en']);

    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: $user->id,
        templateKey: 'messaging.announcement',
        payload: new AnnouncementData(title: 'Hello'),
        recipientEmail: $user->email,
        brandId: $brand->id,
    ));

    expect(MessageRecord::query()->latest()->first()?->recipient_locale)->toBe('en');
});

it('renders an address-only send in the brand locale', function (): void {
    Queue::fake();
    $brand = $this->withBrand(sendMessageLocaleGermanBrand());

    app(SendMessage::class)->handle(new SendMessageRequest(
        recipientId: null,
        templateKey: 'messaging.announcement',
        payload: new AnnouncementData(title: 'Hallo'),
        recipientEmail: 'billing@example.test',
    ));

    expect(MessageRecord::query()->latest()->first()?->recipient_locale)->toBe('de');
});
