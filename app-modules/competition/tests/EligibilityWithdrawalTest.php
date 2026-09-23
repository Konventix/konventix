<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\FillIncompleteEntrant;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationStandingAnnounced;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * The §5.1 gate hears that a registration was cancelled or rejected (final
 * review, part 2, I1), and a registration that never emitted its events can
 * be announced into it (`registration:announce-standing`).
 *
 * Every refusal below is asked of the same person twice: once while their
 * registration stands, where the call succeeds or would, and once after it
 * was withdrawn. So a refusal cannot pass because the setup was broken.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */

/**
 * A confirmed registration, the only sanctioned way: Registration's contract
 * events. Returns the registration's ULID.
 */
function eligibilityTestConfirm(string $eventId, string $brandId, string $userId): string
{
    $registrationId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_submit',
        submittedAt: now()->toIso8601String(),
    ));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    return $registrationId;
}

function eligibilityTestCancel(string $registrationId): void
{
    event(new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: null,
        reasonCode: 'refunded',
        cancelledAt: now()->toIso8601String(),
    ));
}

it('refuses a cancelled registrant everywhere the §5.1 gate stands: enter, join, interest, fill-up and the person search', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    // Team size 2, so one captain enters as `incomplete` and the desk has a
    // slot to fill.
    $competition = Competition::factory()->create([
        'discipline_id' => Discipline::factory()->teamSize(2)->create()->getKey(),
        'event_id' => $eventId,
    ]);

    $captain = (string) Str::ulid();
    eligibilityTestConfirm($eventId, $brand->id, $captain);

    $guest = (string) Str::ulid();
    $registrationId = eligibilityTestConfirm($eventId, $brand->id, $guest);
    competitionParticipantName($guest, 'Refunded Rita', 'rita');

    // Standing: the gate lets the guest in, and the search finds her.
    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $guest))->toBeTrue()
        ->and(ParticipantProfile::searchEligible($brand->id, $eventId, 'Rita')->pluck('user_id')->all())->toBe([$guest]);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Kellerkinder', $captain);
    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$captain]);
    expect($entrant->state)->toBe(EntrantState::Incomplete);

    // Her own team, formed while she stood, entered only after the refund.
    $ownTeam = app(CreateTeam::class)->handle($brand->id, $eventId, 'Ritas Team', $guest);

    eligibilityTestCancel($registrationId);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $guest))->toBeFalse()
        // The row stays with its registrant; only the confirmation goes.
        ->and(EligibleRegistration::withoutBrandScope()->where('registration_id', $registrationId)->value('user_id'))->toBe($guest)
        ->and(ParticipantProfile::searchEligible($brand->id, $eventId, 'Rita')->all())->toBe([]);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $ownTeam, $ownTeam->memberUserIds()))
        ->toThrow(CompetitionEntryException::class);
    expect(fn () => app(CreateTeam::class)->handle($brand->id, $eventId, 'Noch ein Team', $guest))
        ->toThrow(CompetitionEntryException::class);
    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, (string) $team->refresh()->invite_code, $guest))
        ->toThrow(CompetitionEntryException::class);
    expect(fn () => app(ExpressInterest::class)->handle($competition, $guest, 'Suche Team'))
        ->toThrow(CompetitionEntryException::class);
    expect(fn () => app(FillIncompleteEntrant::class)->handle($entrant->refresh(), [$guest]))
        ->toThrow(CompetitionEntryException::class);

    // Nothing of it landed: one member on the captain's team, no entry for hers.
    expect(CompetitionTeam::query()->whereKey($team->id)->firstOrFail()->memberUserIds())->toBe([$captain])
        ->and($entrant->refresh()->state)->toBe(EntrantState::Incomplete)
        ->and($competition->entrants()->where('competition_team_id', $ownTeam->id)->exists())->toBeFalse();
});

it('takes a rejected registration back the same way', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    $registrationId = eligibilityTestConfirm($eventId, $brand->id, $userId);
    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeTrue();

    event(new RegistrationRejected(
        registrationId: $registrationId,
        rejectedByUserId: (string) Str::ulid(),
        reasonCode: 'not_eligible',
        rejectedAt: now()->toIso8601String(),
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeFalse();
});

it('keeps a person eligible through another confirmed registration for the same event', function (): void {
    // The gate is "a confirmed registration for them" (§5.1): cancelling one
    // of two leaves the other standing.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    $first = eligibilityTestConfirm($eventId, $brand->id, $userId);
    eligibilityTestConfirm($eventId, $brand->id, $userId);

    eligibilityTestCancel($first);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeTrue();
});

it('hears a cancellation with no brand in context, the way a refund webhook or the console delivers it', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    $registrationId = eligibilityTestConfirm($eventId, $brand->id, $userId);

    app(BrandContext::class)->forget();
    eligibilityTestCancel($registrationId);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeFalse();
});

it('builds eligibility from the backfill for a registration that never emitted its events, and unlearns it for a cancelled one', function (): void {
    // The hand-run Eventula import writes registrations directly: Competition
    // never heard of them until `registration:announce-standing` restates them.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $imported = (string) Str::ulid();
    $refunded = (string) Str::ulid();

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $imported))->toBeFalse();

    event(new RegistrationStandingAnnounced(
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        registrantUserId: $imported,
        confirmed: true,
    ));

    // A registration the projection saw confirmed, later cancelled while the
    // listener could not hear it: the replay says `confirmed: false`.
    $registrationId = eligibilityTestConfirm($eventId, $brand->id, $refunded);

    event(new RegistrationStandingAnnounced(
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        registrantUserId: $refunded,
        confirmed: false,
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $imported))->toBeTrue()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $refunded))->toBeFalse()
        // Upserted on the registration, never duplicated by a replay.
        ->and(EligibleRegistration::withoutBrandScope()->where('registration_id', $registrationId)->count())->toBe(1);
});
