<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;

/**
 * The desk owes no proof (design §14, "The desk owes no proof"). A `required`
 * policy binds participants. The desk has no upload control (#322) and the
 * participant pages say the desk collects the proof, so an organizer entry
 * without one is the designed path. The gate still answers the desk in
 * English and a participant in German (ADR-0001).
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 *
 * @return array{0: Stage, 1: Entrant}
 */
function deskProofStage(ProofPolicy $policy): array
{
    $competition = Competition::factory()->create([
        'proof_policy' => $policy,
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);

    $stage = Stage::factory()->leaderboard(new LeaderboardSettings)->create(['competition_id' => $competition->getKey()]);
    $entrant = Entrant::factory()->create(['competition_id' => $competition->getKey()]);

    return [$stage, $entrant];
}

it('lets the desk enter a score without a proof where the competition requires one', function (): void {
    $this->withBrand();

    [$stage, $entrant] = deskProofStage(ProofPolicy::Required);

    $entry = app(SubmitScoreEntry::class)->handle($stage, $entrant, new ScoreEntryData(value: 41.5), (string) Str::ulid(), asOrganizer: true);

    // The desk's own row is verified on creation (§8.3) and carries no proof.
    expect($entry->isVerified())->toBeTrue()
        ->and($entry->proof_media_id)->toBeNull();
});

it('still refuses a participant without a proof, in German', function (): void {
    $this->withBrand();

    [$stage, $entrant] = deskProofStage(ProofPolicy::Required);

    try {
        app(SubmitScoreEntry::class)->handle($stage, $entrant, new ScoreEntryData(value: 41.5), (string) Str::ulid());
        $this->fail('A participant score without a proof was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['proofMediaId'] ?? [])->toBe(['Dieses Turnier braucht zu jedem Ergebnis ein Beweisbild.']);
    }

    expect(ScoreEntry::query()->where('stage_id', $stage->getKey())->count())->toBe(0);
});

it('answers the desk in English when it sends a proof to a competition that takes none', function (): void {
    $this->withBrand();

    [$stage, $entrant] = deskProofStage(ProofPolicy::None);

    try {
        app(SubmitScoreEntry::class)->handle(
            $stage,
            $entrant,
            new ScoreEntryData(value: 41.5, proofMediaId: (string) Str::ulid()),
            (string) Str::ulid(),
            asOrganizer: true,
        );
        $this->fail('The desk attached a proof to a competition that takes none.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['proofMediaId'] ?? [])->toBe(['This competition does not take proof images.']);
    }
});
