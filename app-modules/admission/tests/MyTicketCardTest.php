<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
        ->and($payload->reentryHint)->toContain('every day');
});

it('falls back to the open-door hint when the event carries no profile', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    $user = User::factory()->create();
    $admission = Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $user->id]);

    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'admission.my-ticket');
    $payload = ($card->payload)(hubActiveEventFor($brand, $user, $eventId, $admission->id), $user);

    expect($payload->eventName)->toBeNull()
        ->and($payload->reentryHint)->toContain('any time');
});

/**
 * The card renders a signed entrance credential out of an admission id
 * that reached it through a projection in another module. Brand scope
 * alone is not enough: a hub-access row naming an admission of somebody
 * else's — a bad write, a stale mirror, a botched replay — would have this
 * card handing the viewer a stranger's QR. The lookup asks for the
 * viewer's own admission, and a card with no row is dropped by the page's
 * guard (D-HUB-05), which is the failure worth having.
 */
it('refuses to build a card from an admission the viewer does not hold', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $stranger = User::factory()->create();
    $admission = Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $stranger->id]);
    $viewer = User::factory()->create();

    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'admission.my-ticket');

    expect(fn () => ($card->payload)(hubActiveEventFor($brand, $viewer, $eventId, $admission->id), $viewer))
        ->toThrow(ModelNotFoundException::class);
});

// And the same thing through the page: the containment drops the card and
// the hub still renders, rather than issuing the credential.
it('drops the ticket card instead of issuing a stranger\'s credential', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $stranger = ticketPerson();
    $admission = Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $stranger->id]);
    $viewer = User::factory()->create();

    // A hub-access row for the viewer that names somebody else's admission.
    event(hubAdmitted($brand->id, $viewer->id, $eventId, admissionId: $admission->id));

    $this->actingAs($viewer)->get('/live')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/live/Hub')
            ->where('hub.cards', fn (Collection $cards): bool => $cards->doesntContain(fn (array $card): bool => $card['key'] === 'admission.my-ticket')));
});

// The card, through the real thing: the hub registry every provider filled
// at boot, the `/live` route, and a hub access driven by the id of an
// admission that was actually issued. The two Platform page tests render
// against a registry they own (`hubIsolatedRegistry()`), so without this
// one nothing would notice the card 500ing the page it is the first thing
// on — which is exactly what the first review round found.
it('renders on /live against the registry every module filled at boot', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()->create(['event_id' => $eventId, 'reentry_policy' => ReentryPolicy::OncePerDay]);
    $holder = ticketPerson();
    $admission = Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $holder->id]);
    $user = User::factory()->create(['id' => $holder->id]);
    event(hubAdmitted($brand->id, $user->id, $eventId, admissionId: $admission->id));

    // First of the cards because D-HUB-05 orders it first (order 10, ahead
    // of Content's 20) — the ticket is what somebody at the door opens the
    // hub for.
    $this->actingAs($user)->get('/live')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/live/Hub')
            ->where('hub.cards.0.key', 'admission.my-ticket')
            ->where('hub.cards.0.payload.admissionId', $admission->id)
            ->where('hub.cards.0.payload.qrSvg', fn (string $svg): bool => str_contains($svg, '<svg'))
            ->where('hub.cards.0.payload.reentryHint', fn (string $hint): bool => str_contains($hint, 'every day')));
});
