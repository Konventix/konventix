<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Data\MyTicketCardData;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Identity\Models\User;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Hub\HubRegistry;

/**
 * D-HUB-05: the first card of the live hub is the ticket the door will
 * scan. The card is the ticket page's content without the page — the same
 * `current()` token (never `rotate()`, `.ai/rules/tickets.md`) and the
 * same re-entry rule the event carries (D-ADM-03), said in the words a
 * participant standing at the door needs.
 */
it('builds the ticket card for the admitted admission', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()->create(['event_id' => $eventId, 'reentry_policy' => ReentryPolicy::OncePerDay]);
    $holder = ticketPerson();
    $admission = Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $holder->id]);
    // `Person` is Identity's account projected by id (`ProjectPerson`), so
    // the holder's person id *is* the user id.
    $user = User::factory()->create(['id' => $holder->id]);
    event(presenceScan($admission));

    $this->actingAs($user);

    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'admission.my-ticket');
    $payload = ($card->payload)(
        app(ActiveEventContext::class)->all()->first() ?? hubActiveEventFor($brand, $user, $eventId, $admission->id),
        $user,
    );

    expect($payload)->toBeInstanceOf(MyTicketCardData::class)
        ->and($payload->admissionId)->toBe($admission->id)
        ->and($payload->eventName)->toBe('Kon Fest 2026')
        ->and($payload->qrSvg)->toContain('<svg')
        ->and($payload->ticketUrl)->toBe('/my/tickets/'.$admission->id)
        ->and($payload->reentryHint)->toContain('täglich');
});

it('falls back to the open-door hint when the event carries no profile', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);
    $user = User::factory()->create();

    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'admission.my-ticket');
    $payload = ($card->payload)(hubActiveEventFor($brand, $user, $eventId, $admission->id), $user);

    expect($payload->eventName)->toBeNull()
        ->and($payload->reentryHint)->toContain('jederzeit');
});
