<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;

/**
 * The intent-event exception (ADR-0041 point 8): AnnouncementPublished and
 * DunningLevelRaised address Messaging without naming a template, so their
 * listeners live here — with Messaging-owned topics and wording.
 */
function intentEventsRecipient(string $email = 'erika@example.test'): string
{
    $userId = (string) Str::ulid();
    event(new UserRegistered(
        userId: $userId,
        brandId: (string) Str::ulid(),
        email: $email,
        displayName: 'Erika',
        username: 'erika',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    return $userId;
}

function intentEventsDunning(string $brandId, ?string $email = 'billing@example.test'): DunningLevelRaised
{
    return new DunningLevelRaised(
        invoiceId: (string) Str::ulid(),
        invoiceNumber: 'KVX-2026-00042',
        orderId: (string) Str::ulid(),
        brandId: $brandId,
        level: 2,
        daysOverdue: 15,
        raisedAt: now()->toIso8601String(),
        billingName: 'Beispiel GmbH',
        billingEmail: $email,
        totalGross: '119.00',
        currency: 'EUR',
        dueOn: '2026-08-01',
    );
}

it('sends a dunning notice to the billing contact when a level is raised', function (): void {
    $brand = $this->withBrand();

    Mail::fake();

    event(intentEventsDunning($brand->getKey()));

    $row = MessageRecord::withoutBrandScope()->sole();
    expect($row->template_key)->toBe('messaging.dunning-notice')
        ->and($row->topic_key)->toBe('commerce.dunning')
        ->and($row->recipient_email)->toBe('billing@example.test')
        ->and($row->recipient_id)->toBeNull()
        ->and($row->status)->toBe(MessageStatus::Sent)
        ->and($row->payload['invoiceNumber'])->toBe('KVX-2026-00042');
});

it('skips a dunning notice with no billing address rather than aborting the sweep', function (): void {
    $brand = $this->withBrand();

    Mail::fake();

    event(intentEventsDunning($brand->getKey(), email: null));

    expect(MessageRecord::withoutBrandScope()->count())->toBe(0);
});

it('holds a normal announcement for the digest window on every channel', function (): void {
    $brand = $this->withBrand();
    $first = intentEventsRecipient();
    $second = intentEventsRecipient('max@example.test');

    Mail::fake();

    event(new AnnouncementPublished(
        entryId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        eventId: null,
        title: 'Doors open at noon',
        urgency: 'normal',
        audience: 'everyone',
        publishedAt: now()->toIso8601String(),
    ));

    $rows = MessageRecord::withoutBrandScope()->get();

    // Two recipients × the topic's three channels, all held: a normal
    // announcement coalesces, it does not burst.
    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('status')->unique()->all())->toBe([MessageStatus::Held])
        ->and($rows->pluck('recipient_id')->unique()->sort()->values()->all())
        ->toBe(collect([$first, $second])->sort()->values()->all());

    Mail::assertNothingSent();
});

it('delivers an urgent announcement immediately, bell included', function (): void {
    $brand = $this->withBrand();
    $userId = intentEventsRecipient();

    Mail::fake();

    event(new AnnouncementPublished(
        entryId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        eventId: null,
        title: 'Hall B is closed',
        urgency: 'urgent',
        audience: 'everyone',
        publishedAt: now()->toIso8601String(),
    ));

    expect(
        MessageRecord::withoutBrandScope()->pluck('status')->unique()->all(),
    )->toBe([MessageStatus::Sent]);

    $inbox = InboxNotification::withoutBrandScope()->sole();
    expect($inbox->recipient_id)->toBe($userId)
        ->and($inbox->title)->toBe('Hall B is closed')
        ->and($inbox->topic_key)->toBe('content.announcements');
});
