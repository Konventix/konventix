<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\Track;
use Konventix\Competition\Events\CompetitionUnpublished;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * The programme editor's competition half (timetable design §4.5, §6,
 * D-AGD-02): the organizer picks which of the event's announced
 * competitions the programme shows, under which track and in what order.
 *
 * Every brand-owned row — tracks, entries, the projected competitions — is
 * created before the first admin-host request: that host resolves no brand
 * and hardens `BrandScope` to nothing (`.ai/rules/tests.md`). The console
 * host is in the URL for the same reason `AgendaAdminTest` gives.
 *
 * Competitions reach Agenda only through Competition's events, so every
 * test emits them (`agendaPublishedCompetition()`) rather than writing
 * `agenda_competitions` rows.
 */

/**
 * The stored selection of one event, as `[competition_id => [track_id,
 * position]]`, read past the brand scope.
 *
 * @return array<string, array{0: string|null, 1: int}>
 */
function agendaSelectionOf(string $eventId): array
{
    return CompetitionEntry::withoutBrandScope()
        ->where('event_id', $eventId)
        ->orderBy('position')
        ->get()
        ->mapWithKeys(fn (CompetitionEntry $entry): array => [$entry->competition_id => [$entry->track_id, $entry->position]])
        ->all();
}

it('offers the event\'s published competitions and none of another event\'s', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);

    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), [
        'name' => 'CS2 5v5',
        'slug' => 'cs2-5v5',
        'startsAt' => '2026-11-20T14:00:00+01:00',
    ]);
    $mario = agendaPublishedCompetition($brand, $event->getKey(), [
        'name' => 'Mario Kart',
        'slug' => 'mario-kart',
        'startsAt' => null,
    ]);
    $elsewhere = agendaPublishedCompetition($brand, (string) Str::ulid(), [
        'name' => 'Fremdes Turnier',
        'slug' => 'fremdes-turnier',
    ]);

    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertInertia(fn ($page) => $page
            ->component('admin/agenda/Edit')
            // Two of this event's, none selected — the other event's is absent.
            ->has('competitions', 2)
            // Unselected rows are ordered by start with the unscheduled one
            // last, so CS2 (13:00 UTC) comes first and Mario Kart second.
            ->where('competitions.0.competitionId', $cs2)
            ->where('competitions.0.name', 'CS2 5v5')
            // 14:00 at +01:00 is 13:00 UTC.
            ->where('competitions.0.startsAt', '2026-11-20T13:00:00+00:00')
            ->where('competitions.0.selected', false)
            ->where('competitions.0.withdrawn', false)
            ->where('competitions.0.cancelled', false)
            ->where('competitions.1.competitionId', $mario)
            ->where('competitions.1.startsAt', null)
            ->where('competitions', fn ($rows): bool => collect($rows)->pluck('competitionId')->doesntContain($elsewhere)));
});

it('saves a selection of two with a track each, and replaces it whole on the next save', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $stage = Track::factory()->forEvent($event->getKey())->create(['name' => 'Bühne', 'position' => 0]);
    $arena = Track::factory()->forEvent($event->getKey())->create(['name' => 'Arena', 'position' => 1]);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    $mario = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'Mario Kart', 'slug' => 'mario-kart']);
    $rocket = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'Rocket League 2v2', 'slug' => 'rocket-league-2v2']);
    [$operator, $admin] = agendaAdminOperator($brand);

    $url = "http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions";

    $this->actingAs($admin)
        ->put($url, ['competitions' => [
            ['competitionId' => $cs2, 'trackId' => $stage->id, 'position' => 0],
            ['competitionId' => $mario, 'trackId' => $arena->id, 'position' => 1],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $firstCs2Row = CompetitionEntry::withoutBrandScope()->where('competition_id', $cs2)->sole();

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $cs2 => [$stage->id, 0],
        $mario => [$arena->id, 1],
    ])
        ->and($firstCs2Row->brand_id)->toBe($brand->getKey());

    $this->app->forgetScopedInstances();

    // The second save names Rocket League and CS2, and not Mario Kart: the
    // stored set becomes exactly that, CS2 moved to second and untracked.
    $this->actingAs($admin)
        ->put($url, ['competitions' => [
            ['competitionId' => $rocket, 'trackId' => $arena->id, 'position' => 0],
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 1],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $rocket => [$arena->id, 0],
        $cs2 => [null, 1],
    ])
        // Written in place, not deleted and re-created.
        ->and(CompetitionEntry::withoutBrandScope()->where('competition_id', $cs2)->sole()->id)->toBe($firstCs2Row->id);
});

/**
 * The boundary that matters: the ids come from the browser, so a
 * competition of another event — or of another brand, even one projected
 * under this very event id — must not become part of this programme by
 * posting its ULID. The whole set is refused, the valid row included.
 */
it('refuses a competition of another event, and one of another brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $own = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    $otherEvent = agendaPublishedCompetition($brand, (string) Str::ulid(), ['name' => 'Anderes Event', 'slug' => 'anderes-event']);
    $otherBrand = agendaPublishedCompetition(Brand::factory()->create(), $event->getKey(), ['name' => 'Andere Marke', 'slug' => 'andere-marke']);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $own, 'trackId' => null, 'position' => 0],
            ['competitionId' => $otherEvent, 'trackId' => null, 'position' => 1],
            ['competitionId' => $otherBrand, 'trackId' => null, 'position' => 2],
        ]])
        ->assertSessionHasErrors(['competitions.1.competitionId', 'competitions.2.competitionId'])
        ->assertSessionDoesntHaveErrors(['competitions.0.competitionId']);

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

it('refuses a track of another event', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $foreignTrack = Track::factory()->forEvent((string) Str::ulid())->create(['name' => 'Fremde Bühne']);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $cs2, 'trackId' => $foreignTrack->id, 'position' => 0],
        ]])
        ->assertSessionHasErrors(['competitions.0.trackId'])
        ->assertSessionDoesntHaveErrors(['competitions.0.competitionId']);

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

/**
 * `agenda_competition_entries` is unique on (event_id, competition_id), so a
 * competition posted twice would reach the insert as a unique violation — a
 * 500. `distinct` in `CompetitionEntryData::rules()` is the only thing
 * standing in front of it, and it answers on the repeated row's own key.
 */
it('refuses the same competition twice in one save as a field error, not a 500', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 0],
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 1],
        ]])
        ->assertRedirect()
        ->assertSessionHasErrors(['competitions.1.competitionId']);

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

/**
 * `position` is an `unsignedSmallInteger`, which Postgres stores as a plain
 * `smallint` — it has no unsigned form — so the largest value the column
 * takes is 2^15 - 1 = 32767. One past it must come back as a field error;
 * without the rule's `max` it reached the insert and answered a 500.
 */
it('refuses a position past the column\'s range as a field error, not a 500', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    $mario = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'Mario Kart', 'slug' => 'mario-kart']);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            // 32767 is the ceiling itself and passes; 32768 is one past it.
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 32767],
            ['competitionId' => $mario, 'trackId' => null, 'position' => 32768],
        ]])
        ->assertRedirect()
        ->assertSessionHasErrors(['competitions.1.position'])
        ->assertSessionDoesntHaveErrors(['competitions.0.position']);

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

/**
 * Task 3, decision 2: unpublishing deletes the read-model row and keeps the
 * organizer's entry. The editor shows that entry as withdrawn and posts it
 * back with every save — which the Action must accept, though Agenda no
 * longer knows the competition — until the organizer removes it.
 */
it('keeps a withdrawn competition in the set when the editor saves it back, and removes it when told to', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $stage = Track::factory()->forEvent($event->getKey())->create(['name' => 'Bühne']);
    $withdrawn = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    $standing = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'Mario Kart', 'slug' => 'mario-kart']);
    CompetitionEntry::factory()->forEvent($event->getKey())->create([
        'competition_id' => $withdrawn, 'track_id' => $stage->id, 'position' => 0,
    ]);
    CompetitionEntry::factory()->forEvent($event->getKey())->create([
        'competition_id' => $standing, 'track_id' => null, 'position' => 1,
    ]);

    event(new CompetitionUnpublished(
        competitionId: $withdrawn,
        eventId: $event->getKey(),
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-01T09:00:00+00:00',
    ));

    [$operator, $admin] = agendaAdminOperator($brand);
    $editor = "http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda";

    $this->actingAs($admin)
        ->get($editor)
        ->assertInertia(fn ($page) => $page
            // Both entries, in the organizer's order: the withdrawn one at
            // position 0, then Mario Kart at position 1.
            ->has('competitions', 2)
            ->where('competitions.0.competitionId', $withdrawn)
            ->where('competitions.0.withdrawn', true)
            ->where('competitions.0.selected', true)
            // Agenda no longer knows its name.
            ->where('competitions.0.name', 'Withdrawn competition')
            ->where('competitions.0.trackId', $stage->id)
            ->where('competitions.1.competitionId', $standing)
            ->where('competitions.1.withdrawn', false)
            ->where('competitions.1.selected', true));

    $this->app->forgetScopedInstances();

    // Saved back exactly as the editor shows it.
    $this->actingAs($admin)
        ->put("{$editor}/competitions", ['competitions' => [
            ['competitionId' => $withdrawn, 'trackId' => $stage->id, 'position' => 0],
            ['competitionId' => $standing, 'trackId' => null, 'position' => 1],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $withdrawn => [$stage->id, 0],
        $standing => [null, 1],
    ]);

    $this->app->forgetScopedInstances();

    // The organizer removed the withdrawn row: Mario Kart alone, renumbered.
    $this->actingAs($admin)
        ->put("{$editor}/competitions", ['competitions' => [
            ['competitionId' => $standing, 'trackId' => null, 'position' => 0],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $standing => [null, 0],
    ]);

    $this->app->forgetScopedInstances();

    // Once removed, it is gone for good: posting it again is refused, since
    // neither a read-model row nor an entry vouches for it any more.
    $this->actingAs($admin)
        ->put("{$editor}/competitions", ['competitions' => [
            ['competitionId' => $withdrawn, 'trackId' => null, 'position' => 0],
        ]])
        ->assertSessionHasErrors(['competitions.0.competitionId']);

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $standing => [null, 0],
    ]);
});

it('refuses the selection without ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey());
    [$operator, $admin] = agendaAdminOperator($brand, ['platform.access-admin']);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 0],
        ]])
        ->assertForbidden();

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

it('404s when agenda is off for the event\'s brand', function (): void {
    $brand = $this->withBrand();
    // Modules default to off (ADR-0003); nothing here switches Agenda on.
    $event = agendaPublishedEvent($brand);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey());
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $cs2, 'trackId' => null, 'position' => 0],
        ]])
        ->assertNotFound();

    expect(agendaSelectionOf($event->getKey()))->toBe([]);
});

/**
 * `summary()`'s operator check covers the new endpoint as it covers the
 * rest of the editor: an editor on operator Y's admin host, holding
 * `ManageAgenda` there and knowing operator X's event id, cannot rewrite
 * X's selection.
 */
it('hides another operator\'s event from an editor holding ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $cs2 = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'CS2 5v5', 'slug' => 'cs2-5v5']);
    $mario = agendaPublishedCompetition($brand, $event->getKey(), ['name' => 'Mario Kart', 'slug' => 'mario-kart']);
    CompetitionEntry::factory()->forEvent($event->getKey())->create([
        'competition_id' => $cs2, 'track_id' => null, 'position' => 0,
    ]);

    [$elsewhere, $stranger] = agendaAdminOperator(
        $brand,
        operator: Operator::factory()->create(),
        host: 'admin.agendaselection-elsewhere.test',
    );

    expect($elsewhere->getKey())->not->toBe($brand->operator_id);

    $this->actingAs($stranger)
        ->put("http://{$elsewhere->admin_host}/admin/events/{$event->getKey()}/agenda/competitions", ['competitions' => [
            ['competitionId' => $mario, 'trackId' => null, 'position' => 0],
        ]])
        ->assertNotFound();

    expect(agendaSelectionOf($event->getKey()))->toBe([
        $cs2 => [null, 0],
    ]);
});
