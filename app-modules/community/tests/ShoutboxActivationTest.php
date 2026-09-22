<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Event\Events\EventConcluded;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Design §12 item 10 and ADR-0059: what the shoutbox is to a brand that
 * has not switched `community` on — nothing at all, and nothing broken —
 * and the two counters an operator watches on a brand that has.
 *
 * ADR-0003's four enforcement points, from outside: the routes answer 404,
 * the hub card is registered at boot and filtered out at render, and
 * Community's listeners on other modules' events do nothing for the brand.
 * The console desk's own 404 for a brand with the module off is
 * `ShoutboxModerationPageTest`'s "finds no desk" case.
 *
 * Names are prefixed `shoutboxActivation*` — Pest helpers and classes in a
 * test file are process-global across every module's suite
 * (`.ai/rules/tests.md`).
 */
final class ShoutboxActivationUnreachableBroadcaster implements Broadcaster
{
    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    /** Stands in for Reverb being down. */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        throw new BroadcastException('Reverb is unreachable.');
    }
}

function shoutboxActivationMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

/**
 * A member the door let in to `$eventId`, arranged the way production
 * arranges it: Admission's `ParticipantAdmitted` (Platform's shared
 * `hubAdmitted()`), which Platform projects into the hub's access and —
 * only where `community` is on — Community into its badge.
 */
function shoutboxActivationAdmitted(string $brandId, string $eventId): User
{
    $user = User::factory()->create();

    event(hubAdmitted($brandId, $user->id, $eventId));

    return $user;
}

it('has no routes and no card for a brand with community off, and the hub still renders', function (): void {
    $brand = $this->withBrand();

    Exceptions::fake();

    $eventId = (string) Str::ulid();
    $ada = shoutboxActivationAdmitted($brand->getKey(), $eventId);

    // A real line in this event's room, left from when the module was on:
    // with the module on, reporting it answers 204, so the 404 below is the
    // gate's and not a message that does not exist.
    $room = Room::factory()->create(['subject_id' => $eventId]);
    $line = Message::factory()->for($room)->create();

    // Admitted, so everything below is refused for the module and not for
    // the person — a stranger would see the same 404s for another reason.
    $this->actingAs($ada)->get('/live/shoutbox')->assertNotFound();
    $this->actingAs($ada)->getJson('/live/shoutbox/messages?after='.$line->id)->assertNotFound();
    $this->actingAs($ada)->postJson('/live/shoutbox/messages', ['body' => 'Hallo?'])->assertNotFound();
    $this->actingAs($ada)->postJson("/live/shoutbox/messages/{$line->id}/report", [])->assertNotFound();
    $this->actingAs($ada)->postJson('/live/shoutbox/presence', ['status' => 'online'])->assertNotFound();
    $this->actingAs($ada)->deleteJson('/live/shoutbox/presence')->assertNotFound();

    $keys = [];

    $this->actingAs($ada)->get('/live')->assertOk()->assertInertia(function (Assert $page) use (&$keys): void {
        /** @var list<array<string, mixed>> $rendered */
        $rendered = $page->toArray()['props']['hub']['cards'];
        $keys = array_column($rendered, 'key');
    });

    expect($keys)->not->toContain('community.shoutbox')
        ->and(Room::withoutBrandScope()->count())->toBe(1)
        ->and(Message::withoutBrandScope()->count())->toBe(1)
        ->and(MessageReport::withoutBrandScope()->count())->toBe(0);

    // "No errors": every refusal above was the gate's quiet 404, and the
    // hub rendered without anything thrown and swallowed on the way.
    Exceptions::assertNothingReported();

    // The discriminator: the same report on the same line, module on.
    $this->activateModule('community', $brand);

    $this->actingAs($ada)->postJson("/live/shoutbox/messages/{$line->id}/report", [])->assertNoContent();

    expect(MessageReport::withoutBrandScope()->where('message_id', $line->id)->count())->toBe(1);
});

/**
 * Both halves in one test on purpose: "not visible" alone would also pass
 * if the card had never been registered, which is the bug this guards.
 */
it('registers the hub card at boot and shows it only once the brand activates the module', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $registry = app(HubRegistry::class);

    expect($registry->all()->contains(fn (HubCard|HubLink $item): bool => $item->key === 'community.shoutbox'))->toBeTrue()
        ->and($registry->visibleCards($user)->contains(fn (HubCard $card): bool => $card->key === 'community.shoutbox'))->toBeFalse();

    $this->activateModule('community');

    expect($registry->visibleCards($user)->contains(fn (HubCard $card): bool => $card->key === 'community.shoutbox'))->toBeTrue();
});

it('lets no listener act for a brand with community off: no badge, no closed room, no error', function (): void {
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();

    // A room left from when the module was on: the listener must not touch
    // it either, which is the gate and not merely the absence of a row.
    $room = Room::factory()->create(['subject_id' => $eventId]);

    shoutboxActivationAdmitted($brand->getKey(), $eventId);
    event(new EventConcluded(eventId: $eventId, brandId: $brand->getKey(), concludedAt: '2026-10-18T20:00:00+00:00'));

    expect(EventPresence::withoutBrandScope()->count())->toBe(0)
        ->and($room->refresh()->closed_at)->toBeNull();

    // The same two events once the brand turns the module on — proof the
    // off case was the gate, not a listener that never ran.
    $this->activateModule('community', $brand);

    shoutboxActivationAdmitted($brand->getKey(), $eventId);
    event(new EventConcluded(eventId: $eventId, brandId: $brand->getKey(), concludedAt: '2026-10-18T20:00:00+00:00'));

    expect(EventPresence::withoutBrandScope()->count())->toBe(1)
        ->and($room->refresh()->closed_at?->toIso8601String())->toBe('2026-10-18T20:00:00+00:00');
});

it('counts a posted line under the brand slug, and its broadcast and a removal\'s as sent', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    // `phpunit.xml` sets `BROADCAST_CONNECTION=null`: `NullBroadcaster`
    // accepts every broadcast, which is exactly a successful send.
    $room = Room::factory()->create();
    $message = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Der Lötkurs beginnt gleich.'));
    app(RemoveMessage::class)->handle($message, (string) Str::ulid(), 'Doppelt');

    $slug = BrandSlugs::of($brand->getKey());

    // One line, and two broadcasts: the line, then its tombstone. The
    // removal is not a posted line.
    expect(shoutboxActivationMetricsRendered())
        ->toContain('konventix_community_messages_posted_total{brand="'.$slug.'"} 1')
        ->toContain('konventix_community_shoutbox_broadcasts_total{brand="'.$slug.'",outcome="sent"} 2')
        ->not->toContain('konventix_community_shoutbox_broadcasts_total{brand="'.$slug.'",outcome="failed"}');
});

// The broadcast is swallowed so an accepted line is never refused, which
// makes its failure silent by construction — this counter is what makes it
// visible (ADR-0059), and the report is what the Logs dashboard reads.
it('counts a broadcast that failed, reports it, and keeps the line', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);
    Exceptions::fake();

    Broadcast::extend('shoutbox-unreachable', fn (): ShoutboxActivationUnreachableBroadcaster => new ShoutboxActivationUnreachableBroadcaster);
    config([
        'broadcasting.connections.shoutbox-unreachable' => ['driver' => 'shoutbox-unreachable'],
        'broadcasting.default' => 'shoutbox-unreachable',
    ]);

    $room = Room::factory()->create();
    $message = app(PostMessage::class)->handle($room, (string) Str::ulid(), new PostMessageData('Reverb ist gerade weg.'));

    $slug = BrandSlugs::of($brand->getKey());

    expect(Message::query()->whereKey($message->id)->exists())->toBeTrue()
        ->and(shoutboxActivationMetricsRendered())
        ->toContain('konventix_community_messages_posted_total{brand="'.$slug.'"} 1')
        ->toContain('konventix_community_shoutbox_broadcasts_total{brand="'.$slug.'",outcome="failed"} 1')
        ->not->toContain('konventix_community_shoutbox_broadcasts_total{brand="'.$slug.'",outcome="sent"}');

    Exceptions::assertReported(BroadcastException::class);
});

/**
 * The catalogue is what `tests/Feature/ObservabilityCoverageTest.php` holds
 * the dashboards to; pinning the list keeps that failure readable.
 */
it('declares every Community metric in the catalogue exactly once', function (): void {
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());

    expect(array_values(array_filter($declared, fn (string $name): bool => str_starts_with($name, 'community_'))))
        ->toBe(['community_messages_posted_total', 'community_shoutbox_broadcasts_total']);
});
