<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Identity\Models\User;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Spatie\LaravelData\Data;

/**
 * Declared in a test file, so process-global under `--parallel` — the name
 * is unique across the suite on purpose (`.ai/rules/tests.md`).
 */
final class HubPageTestPayload extends Data
{
    public function __construct(public string $hello) {}
}

/**
 * The value of the one sample of `$metric` in a rendered scrape, or 0.0
 * when the metric is absent. Reads the value rather than matching the whole
 * line, because promphp orders a metric's labels as it was registered and a
 * literal `{brand="…",module="…"}` assertion would pin that ordering.
 */
function metricSampleValue(string $rendered, string $metric): float
{
    preg_match('/^'.preg_quote($metric, '/').'(?:\{[^}]*\})? ([0-9.e+-]+)$/m', $rendered, $match);

    return (float) ($match[1] ?? 0.0);
}

function hubPageCard(string $key, int $order = 100): HubCard
{
    return new HubCard('platform', $key, $key, 'platform/hub/TestCard', fn (ActiveEventData $e, $u): Data => new HubPageTestPayload($e->eventId), null, $order);
}

// This file is about the page, not about the four launch contributions
// D-HUB-05 registers at boot — see `hubIsolatedRegistry()`.
beforeEach(fn () => hubIsolatedRegistry());

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
    $resolved = false;
    app(HubRegistry::class)->add(
        hubPageCard('platform.shown'),
        // A flag, not a throw: `HubPage` contains a contributor's exception
        // (see the containment test below), so a throwing sentinel here
        // would be swallowed and this test would pass on a page that *did*
        // resolve the hidden card.
        new HubCard('platform', 'platform.hidden', 'hidden', 'platform/hub/TestCard', function () use (&$resolved): Data {
            $resolved = true;

            return new HubPageTestPayload('hidden');
        }),
    );
    event(new EventHubLayoutChanged($eventId, $brand->id, [['key' => 'platform.hidden', 'visible' => false]], false));

    $this->actingAs($user)->get('/live')->assertInertia(fn (Assert $page) => $page
        ->component('platform/live/Hub')
        ->where('hub.cards.0.key', 'platform.shown')
        ->count('hub.cards', 1));

    expect($resolved)->toBeFalse();
});

// D-HUB-05 review: Platform cannot vet a foreign module's payload
// (ADR-0003), so the shell contains it. Without this guard a brand whose
// entrance signing key was retired would get a 500 at `/live` on event day
// — including the `Hilfe` link that is the only way to report it.
it('drops a contribution whose closure throws, renders the rest, and counts it', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, (string) Str::ulid()));
    app(HubRegistry::class)->add(
        hubPageCard('platform.fine', 1),
        new HubCard('platform', 'platform.broken', 'broken', 'platform/hub/TestCard', function (): never {
            throw new RuntimeException('a card payload blew up');
        }, null, 2),
        new HubLink('platform', 'platform.broken-link', 'broken link', function (): never {
            throw new RuntimeException('a link url blew up');
        }, order: 3),
        new HubLink('platform', 'platform.fine-link', 'fine link', fn (): string => '/somewhere', order: 4),
    );

    $this->actingAs($user)->get('/live')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hub.cards.0.key', 'platform.fine')
            ->count('hub.cards', 1)
            ->where('hub.links.0.key', 'platform.fine-link')
            ->count('hub.links', 1));

    $rendered = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

    expect($rendered)->toContain('konventix_platform_hub_card_failures_total')
        ->and(metricSampleValue($rendered, 'konventix_platform_hub_card_failures_total'))->toBe(2.0);
});

/**
 * `UpdateHubLayoutData::rules()` validates the entries one at a time and
 * says nothing about the list, so a hand-built PUT can save the same key
 * twice. The layout is an order, not a bag: the card renders once, and its
 * payload runs once — which for the ticket card is the difference between
 * showing a code and signing a second one.
 */
it('renders a key listed twice in the layout only once', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));

    $runs = 0;
    app(HubRegistry::class)->add(new HubCard(
        'platform', 'platform.once', 'Once', 'platform/hub/TestCard',
        function (ActiveEventData $e, $u) use (&$runs): Data {
            $runs++;

            return new HubPageTestPayload($e->eventId);
        },
    ));

    event(new EventHubLayoutChanged($eventId, $brand->id, [
        ['key' => 'platform.once', 'visible' => true],
        ['key' => 'platform.once', 'visible' => true],
    ], false));

    $this->actingAs($user)->get('/live')->assertInertia(fn (Assert $page) => $page
        ->where('hub.cards', fn (Collection $cards): bool => $cards->pluck('key')->all() === ['platform.once']));

    expect($runs)->toBe(1);
});
