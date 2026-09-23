<?php

declare(strict_types=1);

use Database\Seeders\Demo\DemoCompetitionSeeder;
use Illuminate\Support\Str;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Event\Models\Event;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Models\Registration;

/*
 * #327: the demo seed failed at random with `noConfirmedRegistration`. The
 * compliance slice files a deletion request at the end of every brand, and
 * the erasure leaves the person's registration `confirmed` (Registration only
 * scrubs billing fields) while Competition's handler takes them out of the
 * eligibility read model. A re-run of `konventix:seed` then handed the
 * competition slice a confirmed snapshot containing somebody the gate no
 * longer lets in — which only surfaced when the first run had drawn too few
 * players to seed a bracket and the re-run had enough, i.e. by luck of
 * `$members->random()`.
 *
 * The case is built here on purpose rather than drawn: the erased registrant
 * is put first in the snapshot, so a seeder that trusts the snapshot enters
 * them and throws every time.
 */

/**
 * A registrant confirmed the sanctioned way, through Registration's contract
 * events, so Competition's own listeners project them. Returns the snapshot
 * row the demo command would hand the seeder.
 */
function demoCompetitionSeederConfirmed(Event $event, string $userId): Registration
{
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: $typeId,
        eventId: (string) $event->getKey(),
        brandId: $event->brand_id,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_submit',
        submittedAt: now()->toIso8601String(),
    ));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: $typeId,
        eventId: (string) $event->getKey(),
        brandId: $event->brand_id,
        registrantUserId: $userId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    return (new Registration)->forceFill([
        'id' => $registrationId,
        'event_id' => (string) $event->getKey(),
        'registrant_user_id' => $userId,
    ]);
}

it('enters only players who pass the entry gate, not every confirmed registrant it was handed (#327)', function (): void {
    $brand = $this->withBrand();

    $event = Event::factory()->create(['slug' => 'demo-cup-327']);

    $erased = (string) Str::ulid();
    $eligible = collect(range(1, 8))->map(fn (): string => (string) Str::ulid());

    // The erased registrant first: the field is the first eight players, so
    // a seeder that reads the snapshot alone always tries to enter them.
    $confirmed = collect([$erased, ...$eligible])
        ->map(fn (string $userId): Registration => demoCompetitionSeederConfirmed($event, $userId));

    // What the compliance slice's deletion request does to Competition.
    app(CompetitionGdprHandler::class)->delete(SubjectRef::forUser($erased), DeletionMode::Anonymize);

    // The setup is what the issue says it is: still in the snapshot, no
    // longer past the gate — and everybody else is.
    $mayEnter = fn (string $userId): bool => EligibleRegistration::userMayEnter(
        (string) $brand->getKey(),
        (string) $event->getKey(),
        $userId,
    );

    expect($confirmed->pluck('registrant_user_id')->all())->toContain($erased)
        ->and($mayEnter($erased))->toBeFalse()
        ->and($eligible->every($mayEnter))->toBeTrue();

    app(DemoCompetitionSeeder::class)->handle($event, $confirmed);

    $mainCup = Competition::query()->firstWhere('slug', 'demo-cup-327-main-cup');

    expect($mainCup)->not->toBeNull();

    $entered = Entrant::query()
        ->where('competition_id', $mainCup->getKey())
        ->get()
        ->flatMap(fn (Entrant $entrant): array => $entrant->memberUserIds())
        ->values();

    expect($entered)->toHaveCount(8)
        ->and($entered->all())->not->toContain($erased)
        ->and($entered->sort()->values()->all())->toBe($eligible->sort()->values()->all())
        ->and($entered->every($mayEnter))->toBeTrue();
});
