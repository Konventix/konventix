<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Agenda\Actions\CancelAgendaItem;
use Konventix\Agenda\Actions\PublishAgendaItem;
use Konventix\Agenda\Actions\SaveAgendaItem;
use Konventix\Agenda\Actions\SaveLocations;
use Konventix\Agenda\Actions\SaveTracks;
use Konventix\Agenda\Data\AgendaItemData;
use Konventix\Agenda\Data\LocationData;
use Konventix\Agenda\Data\TrackData;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Events\AgendaItemCancelled;
use Konventix\Agenda\Events\AgendaItemPublished;
use Konventix\Agenda\Events\AgendaItemRescheduled;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;

it('refuses an item with neither time before it reaches the database', function (): void {
    $this->withBrand();

    expect(fn () => AgendaItemData::validateAndCreate([
        'id' => null, 'title' => 'Kein Zeitpunkt', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => null, 'endsAt' => null,
    ]))->toThrow(ValidationException::class);
});

/**
 * The other half of D-AGD-04: "neither" is the *only* refused combination.
 * The end-only shape is the interesting one — `after:startsAt` is not
 * skipped for it the way the docs' wording suggests, it is evaluated and
 * passes, because Laravel resolves the absent `startsAt` to no timestamp
 * at all and compares against null.
 */
it('accepts an item that is open at one end', function (): void {
    $this->withBrand();

    $startOnly = AgendaItemData::validateAndCreate([
        'id' => null, 'title' => 'Einlass ab 12:00', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => '2026-11-20T12:00:00+01:00', 'endsAt' => null,
    ]);

    $endOnly = AgendaItemData::validateAndCreate([
        'id' => null, 'title' => 'Aufbau bis 08:00', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => null, 'endsAt' => '2026-11-21T08:00:00+01:00',
    ]);

    expect($startOnly->endsAt)->toBeNull()
        ->and($endOnly->startsAt)->toBeNull();
});

it('refuses an end that is before its start', function (): void {
    $this->withBrand();

    expect(fn () => AgendaItemData::validateAndCreate([
        'id' => null, 'title' => 'Rückwärts', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => '2026-11-20T16:30:00+01:00', 'endsAt' => '2026-11-20T15:00:00+01:00',
    ]))->toThrow(ValidationException::class);
});

/**
 * Eloquent's datetime cast writes the wall-clock digits it is handed
 * without converting the zone, so a posted "15:00+01:00" would land in the
 * column as 15:00 UTC — an hour late, silently. `SaveAgendaItem` anchors
 * with `->utc()` first; this is the guard on that.
 */
it('stores the posted instant, not its wall-clock digits', function (): void {
    $brand = $this->withBrand();

    $item = app(SaveAgendaItem::class)->handle((string) Str::ulid(), $brand->id, agendaItemData());

    expect($item->fresh()->starts_at?->equalTo(CarbonImmutable::parse('2026-11-20T14:00:00+00:00')))->toBeTrue()
        ->and($item->fresh()->state)->toBe(ItemState::Draft)
        ->and($item->fresh()->brand_id)->toBe($brand->id);
});

it('publishes once, then reschedules', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $item = app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData());
    EventBus::fake([AgendaItemPublished::class, AgendaItemRescheduled::class]);

    app(PublishAgendaItem::class)->handle($item);
    EventBus::assertDispatchedTimes(AgendaItemPublished::class, 1);
    expect($item->fresh()->state)->toBe(ItemState::Published);

    app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData([
        'startsAt' => '2026-11-20T17:00:00+01:00',
    ]), $item->fresh());

    EventBus::assertDispatchedTimes(AgendaItemRescheduled::class, 1);
    EventBus::assertDispatchedTimes(AgendaItemPublished::class, 1);
});

it('says nothing when a published item is saved with the same times', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $item = app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData());
    app(PublishAgendaItem::class)->handle($item);
    EventBus::fake([AgendaItemPublished::class, AgendaItemRescheduled::class]);

    app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData([
        'title' => 'Lötkurs für Anfänger',
    ]), $item->fresh());
    app(PublishAgendaItem::class)->handle($item->fresh());

    expect($item->fresh()->title)->toBe('Lötkurs für Anfänger');
    EventBus::assertNotDispatched(AgendaItemRescheduled::class);
    EventBus::assertNotDispatched(AgendaItemPublished::class);
});

it('says nothing when a draft moves, because nobody had seen the old time', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $item = app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData());
    EventBus::fake([AgendaItemRescheduled::class]);

    app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData([
        'startsAt' => '2026-11-20T17:00:00+01:00',
    ]), $item->fresh());

    EventBus::assertNotDispatched(AgendaItemRescheduled::class);
});

it('cancels with a reason and keeps the row', function (): void {
    $brand = $this->withBrand();
    $item = app(SaveAgendaItem::class)->handle((string) Str::ulid(), $brand->id, agendaItemData());
    app(PublishAgendaItem::class)->handle($item);
    EventBus::fake([AgendaItemCancelled::class]);

    app(CancelAgendaItem::class)->handle($item->fresh(), 'Referent krank');

    expect($item->fresh()->state)->toBe(ItemState::Cancelled)
        ->and($item->fresh()->cancelled_reason)->toBe('Referent krank');
    EventBus::assertDispatched(AgendaItemCancelled::class, fn (AgendaItemCancelled $e): bool => $e->reason === 'Referent krank');
});

/**
 * Design §7's trigger is "a *published* item is cancelled". A draft was
 * never on the page, so there is nobody to tell — announcing it would have
 * a consumer mail "Lötkurs 15:00 fällt aus" for something that never
 * happened. The state still has to be written: the editor's list shows it.
 */
it('calls a draft off without announcing it', function (): void {
    $brand = $this->withBrand();
    $item = app(SaveAgendaItem::class)->handle((string) Str::ulid(), $brand->id, agendaItemData());
    EventBus::fake([AgendaItemCancelled::class]);

    app(CancelAgendaItem::class)->handle($item->fresh(), 'doch kein Referent');

    expect($item->fresh()->state)->toBe(ItemState::Cancelled)
        ->and($item->fresh()->cancelled_reason)->toBe('doch kein Referent');
    EventBus::assertNotDispatched(AgendaItemCancelled::class);
});

/**
 * The other half of the same rule: always persist, emit only on the
 * transition. An organizer correcting the reason on an item that is
 * already off must see the correction stick, and the participants must not
 * be told a second time.
 */
it('corrects the reason of an item that is already off, silently', function (): void {
    $brand = $this->withBrand();
    $item = app(SaveAgendaItem::class)->handle((string) Str::ulid(), $brand->id, agendaItemData());
    app(PublishAgendaItem::class)->handle($item);
    app(CancelAgendaItem::class)->handle($item->fresh(), 'Referent krank');
    EventBus::fake([AgendaItemCancelled::class]);

    app(CancelAgendaItem::class)->handle($item->fresh(), 'verschoben auf Sonntag');

    expect($item->fresh()->cancelled_reason)->toBe('verschoben auf Sonntag')
        ->and($item->fresh()->state)->toBe(ItemState::Cancelled);
    EventBus::assertNotDispatched(AgendaItemCancelled::class);
});

it('replaces the track set while keeping the ids of tracks that stay', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    app(SaveTracks::class)->handle($eventId, $brand->id, [
        TrackData::from(['id' => null, 'name' => 'Bühne', 'position' => 1]),
        TrackData::from(['id' => null, 'name' => 'Turniere', 'position' => 2]),
    ]);
    $stage = Track::query()->where('name', 'Bühne')->firstOrFail();
    $item = AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id]);

    app(SaveTracks::class)->handle($eventId, $brand->id, [
        TrackData::from(['id' => $stage->id, 'name' => 'Hauptbühne', 'position' => 1]),
    ]);

    expect(Track::query()->where('event_id', $eventId)->count())->toBe(1)
        ->and($stage->fresh()->name)->toBe('Hauptbühne')
        ->and($item->fresh()->track_id)->toBe($stage->id);
});

it('replaces the place set and leaves the items of a vanished place placeless', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    app(SaveLocations::class)->handle($eventId, $brand->id, [
        LocationData::from(['id' => null, 'name' => 'Halle 3', 'venueId' => null, 'position' => 1]),
        LocationData::from(['id' => null, 'name' => 'Foyer', 'venueId' => null, 'position' => 2]),
    ]);
    $hall = Location::query()->where('name', 'Halle 3')->firstOrFail();
    $foyer = Location::query()->where('name', 'Foyer')->firstOrFail();
    $talk = AgendaItem::factory()->create(['event_id' => $eventId, 'location_id' => $hall->id]);
    $meetup = AgendaItem::factory()->create(['event_id' => $eventId, 'location_id' => $foyer->id]);

    app(SaveLocations::class)->handle($eventId, $brand->id, [
        LocationData::from(['id' => $hall->id, 'name' => 'Haupthalle', 'venueId' => null, 'position' => 1]),
    ]);

    expect(Location::query()->where('event_id', $eventId)->count())->toBe(1)
        ->and($hall->fresh()->name)->toBe('Haupthalle')
        ->and($talk->fresh()->location_id)->toBe($hall->id)
        ->and($meetup->fresh()->location_id)->toBeNull();
});
