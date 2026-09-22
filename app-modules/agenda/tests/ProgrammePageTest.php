<?php

declare(strict_types=1);

use Konventix\Agenda\Actions\CancelAgendaItem;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\EventSummary;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;

/**
 * The public programme (design §6): the one surface of this module that
 * needs no account, no ticket and no capability. Everything here is
 * therefore an assertion about a request nobody signed in made.
 */
it('shows the published programme to anyone, without an account', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    agendaPublishedItem($event->getKey(), 'Eröffnung', '2026-11-20T13:00:00Z', '2026-11-20T14:00:00Z');

    $this->get("/events/{$event->slug}/programm")
        ->assertOk()
        // One of the two days: the other has nothing on it, and
        // `ProgrammeBuilder` omits an empty day rather than offering the
        // switcher a blank grid.
        ->assertInertia(fn ($page) => $page->component('agenda/Programme')->has('days', 1));
});

it('is absent when the module is off for the brand', function (): void {
    $brand = $this->withBrand();
    $event = agendaPublishedEvent($brand);

    $this->get("/events/{$event->slug}/programm")->assertNotFound();
});

/**
 * The route takes a slug and not a bound model, because Agenda may not
 * import Event's Eloquent model (ADR-0004) — so the slug has to resolve
 * against this module's own read model, and that read model has to have
 * been filled by Event's contract events.
 */
it('learns the slug, name, timezone and days from Event contract events alone', function (): void {
    $brand = $this->withBrand();
    $event = agendaPublishedEvent($brand);

    $summary = EventSummary::query()->where('event_id', $event->getKey())->first();

    expect($summary)->not->toBeNull()
        ->and($summary->slug)->toBe('agenda-testcon')
        ->and($summary->name)->toBe('Agenda Testcon')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->state)->toBe('published')
        ->and($summary->programmeDays())->toHaveCount(2)
        ->and($summary->programmeDays()[0]['date'])->toBe('2026-11-20')
        ->and($summary->programmeDays()[0]['label'])->toBe('Freitag');
});

/**
 * A programme is only an announcement once the event is one. An organizer
 * building next year's schedule must not publish it by accident to anyone
 * who guesses the slug — the editor is where that work happens, and it
 * stays open (see `AgendaAdminTest`).
 */
it('does not serve the programme of an event that is still a draft', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand, publish: false);
    agendaPublishedItem($event->getKey(), 'Eröffnung', '2026-11-20T13:00:00Z', '2026-11-20T14:00:00Z');

    $this->get("/events/{$event->slug}/programm")->assertNotFound();
});

it('serves it again once the event is published, and stops when it is unpublished', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand, publish: false);
    agendaPublishedItem($event->getKey(), 'Eröffnung', '2026-11-20T13:00:00Z', '2026-11-20T14:00:00Z');

    event(new EventPublished(
        eventId: $event->getKey(),
        brandId: $brand->getKey(),
        publishedAt: '2026-09-01 12:00:00',
    ));

    $this->get("/events/{$event->slug}/programm")->assertOk();

    // Unpublishing returns the event to `Announced`, which is not public.
    event(new EventUnpublished(
        eventId: $event->getKey(),
        brandId: $brand->getKey(),
    ));

    $this->get("/events/{$event->slug}/programm")->assertNotFound();
});

/**
 * The rollout step of `docs/operations.md` § Programme, end to end, and the
 * one state no other test here builds: an event that predates this module
 * has emitted no contract event since it was created, so Agenda has no row
 * for it at all — not a stale one, none.
 *
 * Deliberately **not** `agendaPublishedEvent()`. That helper emits
 * `EventDaysChanged` itself, which is exactly what production does not
 * have here, and it is why the missing day set stayed invisible: the state
 * gap showed up as a 404, this one as a 200 with "noch kein Programm
 * veröffentlicht" over a programme that is fully published.
 */
it('gives a backfilled event its day set, not only its state', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');

    // A factory row emits nothing, which is the point: this is the event as
    // it sits in the database on the morning of the deploy.
    $event = Event::factory()->published()->create([
        'name' => 'Altcon',
        'slug' => 'altcon',
        'starts_at' => '2026-11-20 10:00:00',
        'ends_at' => '2026-11-21 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);
    EventDay::factory()->forEvent($event, 1)->create(['date' => '2026-11-20', 'label' => 'Freitag']);
    EventDay::factory()->forEvent($event, 2)->create(['date' => '2026-11-21', 'label' => 'Samstag']);

    // The organizer switches Agenda on and builds the programme first —
    // the editor works without any projection of the lifecycle state.
    agendaPublishedItem($event->getKey(), 'Eröffnung', '2026-11-20T13:00:00Z', '2026-11-20T14:00:00Z');

    expect(EventSummary::query()->where('event_id', $event->getKey())->exists())->toBeFalse();

    $this->artisan('event:replay-details', ['--with-state' => true])->assertSuccessful();

    $summary = EventSummary::query()->where('event_id', $event->getKey())->firstOrFail();

    expect($summary->state)->toBe('published')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->programmeDays())->toHaveCount(2)
        ->and($summary->programmeDays()[0]['label'])->toBe('Freitag');

    $this->get('/events/altcon/programm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agenda/Programme')
            ->has('days', 1)
            ->where('days.0.untracked.0.title', 'Eröffnung'));
});

it('404s on a slug this module has never been told about', function (): void {
    $this->withBrand();
    $this->activateModule('agenda');

    $this->get('/events/never-heard-of-it/programm')->assertNotFound();
});

/**
 * D-AGD-08: a called-off item stays on the page rather than disappearing on
 * somebody who planned an afternoon around it.
 */
it('keeps a cancelled item on the page, with its reason', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);

    $item = agendaPublishedItem($event->getKey(), 'Lötkurs', '2026-11-20T14:00:00Z', '2026-11-20T15:00:00Z');
    app(CancelAgendaItem::class)->handle($item, 'Referent krank');

    $this->get("/events/{$event->slug}/programm")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agenda/Programme')
            ->has('days', 1)
            ->where('days.0.untracked.0.title', 'Lötkurs')
            ->where('days.0.untracked.0.cancelled', true)
            ->where('days.0.untracked.0.cancelledReason', 'Referent krank'));
});

it('leaves a draft off the page entirely', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);

    AgendaItem::factory()
        ->forEvent($event->getKey())
        ->create(['title' => 'Noch geheim', 'starts_at' => '2026-11-20 13:00:00', 'ends_at' => '2026-11-20 14:00:00']);

    // No published item at all, so no day survives.
    $this->get("/events/{$event->slug}/programm")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('agenda/Programme')->has('days', 0));
});
