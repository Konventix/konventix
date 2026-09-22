<?php

declare(strict_types=1);

use Konventix\Agenda\Actions\CancelAgendaItem;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\EventSummary;

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
        ->and($summary->programmeDays())->toHaveCount(2)
        ->and($summary->programmeDays()[0]['date'])->toBe('2026-11-20')
        ->and($summary->programmeDays()[0]['label'])->toBe('Freitag');
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
