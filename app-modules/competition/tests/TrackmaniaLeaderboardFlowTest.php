<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\FinishCompetition;
use Konventix\Competition\Actions\RejectScoreEntry;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Actions\StoreProofImage;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Actions\VerifyScoreEntry;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\LeaderboardAggregate;
use Konventix\Competition\Enums\LeaderboardDirection;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Platform\Models\MediaItem;

/**
 * Definition of done, archetype two (#32, design §13): a Trackmania time
 * attack run as a leaderboard, with proofs and verification (§7.2, §8.3,
 * §8.7).
 *
 * Three solo players enter. The competition requires a proof with every
 * time, so a time without one is refused. Each player uploads a screenshot
 * through the participant's door (`StoreProofImage`) and submits their best
 * time with it. Nothing counts until the desk verifies it. The desk verifies
 * two, rejects the third with a reason, the player resubmits, and the desk
 * verifies that. A verified time can no longer be replaced by its player.
 *
 * Times by hand, lower is better, one best time each: Sabine 41.900 s,
 * Tom 41.950 s, and Mia 43.000 s rejected and then 42.500 s. The ranking is
 * Sabine 1st, Tom 2nd, Mia 3rd. All three are exact in binary floating point
 * to the column's three decimals, so `toBe()` is safe.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function trackmaniaFlowProof(Competition $competition, Entrant $entrant, string $userId): string
{
    return app(StoreProofImage::class)->handle(
        $competition,
        $entrant,
        UploadedFile::fake()->image('lauf.png', 640, 360),
        $userId,
    );
}

it('runs a time attack from signup to final ranking, with proofs, a rejection and verification', function (): void {
    $brand = $this->withBrand();
    $brandId = (string) $brand->getKey();
    $eventId = (string) Str::ulid();

    Storage::fake('media');

    $competition = Competition::factory()->create([
        'event_id' => $eventId,
        'name' => 'Trackmania Time Attack',
        'slug' => 'trackmania-time-attack',
        'discipline_id' => Discipline::factory()->videoGame()->teamSize(1)->create(['name' => 'Trackmania'])->getKey(),
        'result_submission_mode' => ResultSubmissionMode::Participants,
        'proof_policy' => ProofPolicy::Required,
    ]);

    $stage = Stage::factory()
        ->leaderboard(new LeaderboardSettings(
            direction: LeaderboardDirection::Lower,
            aggregate: LeaderboardAggregate::Best,
            heats: null,
            unit: 's',
            decimals: 3,
        ))
        ->create(['competition_id' => $competition->getKey()]);

    /*
     * Signup: three solo players, each a team of one (D-CMP-13).
     */
    $players = [];
    $entrants = [];

    foreach (['Sabine', 'Tom', 'Mia'] as $name) {
        $userId = competitionEligibleUser($eventId, $brandId);
        competitionParticipantName($userId, $name, Str::lower($name));

        $team = app(CreateTeam::class)->handle($brandId, $eventId, $name, $userId);

        $players[$name] = $userId;
        $entrants[$name] = app(EnterCompetition::class)->handle($competition, $team->refresh());
    }

    /*
     * The field closes and the competition starts. A leaderboard has nothing
     * to draw, so it starts on the field as it stands (design §14, "A
     * leaderboard needs no draw to start").
     */
    app(CloseSignup::class)->handle($competition);
    app(StartCompetition::class)->handle($competition->refresh());

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);

    $submit = app(SubmitScoreEntry::class);

    // A time without its proof is refused: the policy is `required`.
    expect(fn () => $submit->handle($stage, $entrants['Mia'], new ScoreEntryData(value: 43.0), $players['Mia']))
        ->toThrow(ValidationException::class);

    /*
     * Each player uploads a screenshot and submits a time with it.
     */
    $times = ['Sabine' => 41.9, 'Tom' => 41.95, 'Mia' => 43.0];
    $entries = [];

    foreach ($times as $name => $seconds) {
        $proof = trackmaniaFlowProof($competition, $entrants[$name], $players[$name]);

        // The proof is one of this brand's own media items (ADR-0035).
        expect(MediaItem::query()->whereKey($proof)->exists())->toBeTrue();

        $entries[$name] = $submit->handle(
            $stage,
            $entrants[$name],
            new ScoreEntryData(value: $seconds, proofMediaId: $proof),
            $players[$name],
        );

        expect($entries[$name]->proof_media_id)->toBe($proof)
            ->and($entries[$name]->isVerified())->toBeFalse();
    }

    // Nothing is verified, so nothing is ranked yet (§8.3).
    expect(ScoreEntry::query()->where('stage_id', $stage->getKey())->whereNull('verified_at')->count())->toBe(3);

    /*
     * The desk works the queue: two verified, Mia's rejected with a reason.
     */
    $desk = (string) Str::ulid();

    app(VerifyScoreEntry::class)->handle($entries['Sabine'], $desk);
    app(VerifyScoreEntry::class)->handle($entries['Tom'], $desk);
    app(RejectScoreEntry::class)->handle($entries['Mia'], $desk, 'Auf dem Bild ist eine andere Strecke.');

    expect($entries['Mia']->refresh()->isRejected())->toBeTrue()
        ->and($entries['Mia']->rejection_note)->toBe('Auf dem Bild ist eine andere Strecke.');

    /*
     * Mia resubmits with the right screenshot. The new time replaces the
     * rejected one on the same row, and the rejection goes with the old
     * number (§8.3).
     */
    $resubmitted = $submit->handle(
        $stage,
        $entrants['Mia'],
        new ScoreEntryData(value: 42.5, proofMediaId: trackmaniaFlowProof($competition, $entrants['Mia'], $players['Mia'])),
        $players['Mia'],
    );

    expect($resubmitted->getKey())->toBe($entries['Mia']->getKey())
        ->and($resubmitted->isRejected())->toBeFalse()
        ->and($resubmitted->isVerified())->toBeFalse();

    app(VerifyScoreEntry::class)->handle($resubmitted, $desk);

    // A verified time stays; only the desk could replace it now.
    expect(fn () => $submit->handle(
        $stage,
        $entrants['Tom'],
        new ScoreEntryData(value: 40.0, proofMediaId: trackmaniaFlowProof($competition, $entrants['Tom'], $players['Tom'])),
        $players['Tom'],
    ))->toThrow(ValidationException::class);

    app(FinishCompetition::class)->handle($competition->refresh());

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Finished);

    /*
     * The final ranking, by hand: lower is better, one best time each.
     * 41.900 < 41.950 < 42.500, so Sabine, Tom, Mia.
     */
    expect(Standing::query()->where('stage_id', $stage->getKey())->pluck('placement', 'entrant_id')->all())
        ->toEqual([
            (string) $entrants['Sabine']->getKey() => 1,
            (string) $entrants['Tom']->getKey() => 2,
            (string) $entrants['Mia']->getKey() => 3,
        ])
        ->and(ScoreEntry::query()->where('stage_id', $stage->getKey())->whereNotNull('verified_at')->pluck('value', 'entrant_id')->map(fn ($value): float => (float) $value)->all())
        ->toEqual([
            (string) $entrants['Sabine']->getKey() => 41.9,
            (string) $entrants['Tom']->getKey() => 41.95,
            (string) $entrants['Mia']->getKey() => 42.5,
        ]);
});
