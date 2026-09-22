<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Identity\Models\User;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Spatie\LaravelData\Data;

/**
 * Declared in a test file, so process-global under `--parallel` — the name
 * is unique across the suite on purpose (`.ai/rules/tests.md`).
 */
final class HubPageTestPayload extends Data
{
    public function __construct(public string $hello) {}
}

function hubPageCard(string $key, int $order = 100): HubCard
{
    return new HubCard('platform', $key, $key, 'platform/hub/TestCard', fn (ActiveEventData $e, $u): Data => new HubPageTestPayload($e->eventId), null, $order);
}

it('redirects a guest to login and a non-admitted member home with a flash', function (): void {
    $this->withBrand();
    $this->get('/live')->assertRedirect('/login');

    // Inertia v3 keeps flashed data under its own session key
    // (`SessionKey::FLASH_DATA`), never under the toast's name.
    $this->actingAs(User::factory()->create())->get('/live')
        ->assertRedirect('/')
        ->assertSessionHas('inertia.flash_data.toast.type', 'info');
});

it('renders the cards in the operator\'s order, hiding the hidden ones', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));
    app(HubRegistry::class)->add(hubPageCard('platform.a', 1), hubPageCard('platform.b', 2), hubPageCard('platform.c', 3));
    event(new EventHubLayoutChanged($eventId, $brand->id, [
        ['key' => 'platform.c', 'visible' => true],
        ['key' => 'platform.a', 'visible' => false],
        ['key' => 'platform.zzz', 'visible' => true],   // stale key, ignored
    ], false));

    $this->actingAs($user)->get('/live')->assertInertia(fn (Assert $page) => $page
        ->component('platform/live/Hub')
        ->where('hub.event.eventId', $eventId)
        ->where('hub.cards.0.key', 'platform.c')
        ->where('hub.cards.1.key', 'platform.b')   // unlisted → appended in registry order
        ->where('hub.cards.0.payload.hello', $eventId)
        ->count('hub.cards', 2));
});

it('never runs the payload of a card the layout hides', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));
    app(HubRegistry::class)->add(
        hubPageCard('platform.shown'),
        new HubCard('platform', 'platform.hidden', 'hidden', 'platform/hub/TestCard', function (): never {
            throw new RuntimeException('payload resolved for a hidden card');
        }),
    );
    event(new EventHubLayoutChanged($eventId, $brand->id, [['key' => 'platform.hidden', 'visible' => false]], false));

    $this->actingAs($user)->get('/live')->assertInertia(fn (Assert $page) => $page
        ->component('platform/live/Hub')
        ->where('hub.cards.0.key', 'platform.shown')
        ->count('hub.cards', 1));
});
