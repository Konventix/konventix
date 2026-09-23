<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionAnnounced;
use Konventix\Competition\Events\CompetitionPublished;
use Konventix\Competition\Models\Competition;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * `competition:announce-competitions` re-emits the narrow CompetitionAnnounced
 * for every competition of every brand, so a read model built after
 * competitions were published can be backfilled. The first such read model is
 * Agenda's programme (timetable design §4.5, #331). The command deliberately
 * does not replay CompetitionPublished, a lifecycle announcement whose
 * consumers would re-notify and re-count.
 *
 * Every test forgets the ambient brand before running the command, because
 * that is the CLI's situation: no brand resolved, and a brand-scoped query
 * would read nothing and still report success.
 */
it('announces every competition of every brand with its current name, slug, status and start', function (): void {
    $first = $this->withBrand();
    $published = Competition::factory()->create([
        'name' => 'Main Cup',
        'slug' => 'main-cup',
        'starts_at' => CarbonImmutable::parse('2026-10-03 14:00:00', 'UTC'),
    ]);
    $draft = Competition::factory()->unpublished()->create([
        'name' => 'Side Bracket',
        'slug' => 'side-bracket',
        'starts_at' => null,
    ]);

    $second = $this->withBrand(Brand::factory()->create());
    $closed = Competition::factory()->status(CompetitionStatus::SignupClosed)->create([
        'name' => 'Open',
        'slug' => 'open',
        'starts_at' => CarbonImmutable::parse('2026-10-04 09:30:00', 'UTC'),
    ]);

    app(BrandContext::class)->forget();
    Event::fake([CompetitionAnnounced::class, CompetitionPublished::class]);

    $this->artisan('competition:announce-competitions')
        ->expectsOutputToContain('Announced 3 competition(s).')
        ->assertSuccessful();

    // Two in the first brand plus one in the second: three.
    Event::assertDispatchedTimes(CompetitionAnnounced::class, 3);

    // The app runs in UTC, so 14:00 UTC comes back as 14:00+00:00.
    Event::assertDispatched(
        CompetitionAnnounced::class,
        fn (CompetitionAnnounced $event): bool => $event->competitionId === $published->id
            && $event->eventId === $published->event_id
            && $event->brandId === $first->id
            && $event->name === 'Main Cup'
            && $event->slug === 'main-cup'
            && $event->status === 'published'
            && $event->startsAt === '2026-10-03T14:00:00+00:00',
    );
    Event::assertDispatched(
        CompetitionAnnounced::class,
        fn (CompetitionAnnounced $event): bool => $event->competitionId === $draft->id
            && $event->eventId === $draft->event_id
            && $event->brandId === $first->id
            && $event->name === 'Side Bracket'
            && $event->slug === 'side-bracket'
            && $event->status === 'draft'
            && $event->startsAt === null,
    );
    Event::assertDispatched(
        CompetitionAnnounced::class,
        fn (CompetitionAnnounced $event): bool => $event->competitionId === $closed->id
            && $event->eventId === $closed->event_id
            && $event->brandId === $second->id
            && $event->name === 'Open'
            && $event->slug === 'open'
            && $event->status === 'signup_closed'
            && $event->startsAt === '2026-10-04T09:30:00+00:00',
    );
});

it('says announced false for a draft and for a competition cancelled before it was ever published, true for one cancelled after', function (): void {
    $this->withBrand();
    $draft = Competition::factory()->unpublished()->create();
    $cancelledFromDraft = Competition::factory()->status(CompetitionStatus::Cancelled)->create(['published_at' => null]);
    $cancelledAfterPublishing = Competition::factory()->status(CompetitionStatus::Cancelled)->create([
        'published_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    app(BrandContext::class)->forget();
    Event::fake([CompetitionAnnounced::class, CompetitionPublished::class]);

    $this->artisan('competition:announce-competitions')->assertSuccessful();

    Event::assertDispatchedTimes(CompetitionAnnounced::class, 3);

    // announced = status !== draft && published_at !== null:
    //   draft:                   status draft,     published_at null → false && … = false
    //   cancelled from draft:    status cancelled, published_at null → true && false = false
    //   cancelled after publish: status cancelled, published_at set  → true && true  = true
    $expected = [
        $draft->id => false,
        $cancelledFromDraft->id => false,
        $cancelledAfterPublishing->id => true,
    ];

    foreach ($expected as $competitionId => $announced) {
        Event::assertDispatched(
            CompetitionAnnounced::class,
            fn (CompetitionAnnounced $event): bool => $event->competitionId === $competitionId
                && $event->announced === $announced,
        );
    }
});

it('never replays CompetitionPublished', function (): void {
    $this->withBrand();
    Competition::factory()->create();

    app(BrandContext::class)->forget();
    Event::fake([CompetitionAnnounced::class, CompetitionPublished::class]);

    $this->artisan('competition:announce-competitions')->assertSuccessful();

    Event::assertDispatchedTimes(CompetitionAnnounced::class, 1);
    Event::assertNotDispatched(CompetitionPublished::class);
});

it('walks the table in chunks', function (): void {
    $this->withBrand();
    Competition::factory()->count(3)->create();

    app(BrandContext::class)->forget();
    Event::fake([CompetitionAnnounced::class]);

    // --chunk=2 over three rows: a chunk of two, then a chunk of one.
    $this->artisan('competition:announce-competitions', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(CompetitionAnnounced::class, 3);
});
