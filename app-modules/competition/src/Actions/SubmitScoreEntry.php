<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use RuntimeException;

/**
 * A score on a leaderboard stage (design §7.2, §8.3).
 *
 * The participant path and the desk path are the same Action with one flag,
 * because they write the same row and differ in exactly one thing: a
 * **desk-entered row is verified on creation** and a participant's is not.
 * Splitting them into two Actions would have duplicated four guards to
 * express one difference.
 *
 * ## What replaces what
 *
 * §8.3's rule, in the order it is checked:
 *
 * - no entry yet for this (stage, entrant, heat) → a new row;
 * - an **unverified** entry → replaced, because a participant improving
 *   their own unconfirmed lap time is the normal case and a table of
 *   superseded attempts helps nobody;
 * - a **verified** entry → refused unless an organizer is doing it. A
 *   verified number has been looked at by a human, and a participant who
 *   could overwrite it would make verification decorative.
 *
 * The unique index `(stage_id, entrant_id, heat)` is `NULLS NOT DISTINCT`, so
 * the database enforces the same one-row rule for a heatless stage that this
 * enforces for a stage with heats.
 *
 * ## What it refuses outright
 *
 * A stage that is not a leaderboard, and an entrant that is not this
 * competition's or is no longer in the field, are programming errors and
 * throw. A heat the stage does not have, and a missing proof where the
 * competition requires one, are things a person can get wrong on a form and
 * come back as field errors.
 */
final class SubmitScoreEntry
{
    public function __construct(
        private readonly CalculateStandings $standings,
    ) {}

    public function handle(
        Stage $stage,
        Entrant $entrant,
        ScoreEntryData $data,
        string $submittedByUserId,
        bool $asOrganizer = false,
    ): ScoreEntry {
        return DB::transaction(function () use ($stage, $entrant, $data, $submittedByUserId, $asOrganizer): ScoreEntry {
            $this->guardStage($stage, $entrant);
            $this->guardHeat($stage, $data);
            $this->guardProof($stage, $data);

            $entry = $this->existing($stage, $entrant, $data->heat);

            if ($entry !== null && $entry->isVerified() && ! $asOrganizer) {
                throw ValidationException::withMessages([
                    'value' => __('This score has already been verified; only an organizer can replace it.'),
                ]);
            }

            $entry ??= new ScoreEntry;

            $entry->fill([
                'stage_id' => $stage->id,
                'entrant_id' => $entrant->id,
                'heat' => $data->heat,
                'value' => $data->value,
                'proof_media_id' => $data->proofMediaId,
                'submitted_by_user_id' => $submittedByUserId,
                'note' => $data->note,
                // A replacement starts unverified again, whoever wrote it:
                // the desk's own row is verified below, and a participant's
                // resubmission must not inherit the verification of the
                // number it replaced.
                'verified_at' => $asOrganizer ? now() : null,
                'verified_by_user_id' => $asOrganizer ? $submittedByUserId : null,
            ]);

            // brand_id from the stage, not ambient context (ADR-0010): the
            // desk runs on the operator host, where no brand resolves.
            $entry->forceFill(['brand_id' => $stage->brand_id])->save();

            if ($entry->isVerified()) {
                $this->standings->handle($stage);
            }

            return $entry->refresh();
        });
    }

    private function existing(Stage $stage, Entrant $entrant, ?int $heat): ?ScoreEntry
    {
        /** @var ScoreEntry|null $entry */
        $entry = ScoreEntry::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->where('entrant_id', $entrant->id)
            ->when($heat === null, fn ($query) => $query->whereNull('heat'))
            ->when($heat !== null, fn ($query) => $query->where('heat', $heat))
            ->first();

        return $entry;
    }

    private function guardStage(Stage $stage, Entrant $entrant): void
    {
        if (! $stage->isLeaderboard()) {
            throw new RuntimeException(sprintf(
                'Stage [%s] runs [%s]; only a leaderboard stage accumulates score entries.',
                $stage->id,
                $stage->format,
            ));
        }

        if ($entrant->competition_id !== $stage->competition_id || $entrant->brand_id !== $stage->brand_id) {
            throw new RuntimeException(sprintf(
                'Entrant [%s] does not belong to stage [%s]\'s competition.',
                $entrant->id,
                $stage->id,
            ));
        }

        if (! $entrant->isActive()) {
            throw new RuntimeException(sprintf(
                'Entrant [%s] is [%s] and no longer scores.',
                $entrant->id,
                $entrant->state->value,
            ));
        }
    }

    private function guardHeat(Stage $stage, ScoreEntryData $data): void
    {
        $settings = $stage->leaderboardSettings();

        if ($settings->acceptsHeat($data->heat)) {
            return;
        }

        throw ValidationException::withMessages([
            'heat' => $settings->heats === null
                ? __('This leaderboard has no heats; submit one entry without a heat number.')
                : __('This leaderboard has :count heats; pick one of them.', ['count' => $settings->heats]),
        ]);
    }

    private function guardProof(Stage $stage, ScoreEntryData $data): void
    {
        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($stage->competition_id)->first();

        // `??` already isolates a null competition; `?->` on its left is
        // redundant and phpstan says so.
        $policy = $competition->proof_policy ?? ProofPolicy::Optional;

        if ($policy === ProofPolicy::Required && $data->proofMediaId === null) {
            throw ValidationException::withMessages([
                'proofMediaId' => __('This competition needs a proof image with every score.'),
            ]);
        }

        if ($policy === ProofPolicy::None && $data->proofMediaId !== null) {
            throw ValidationException::withMessages([
                'proofMediaId' => __('This competition does not take proof images.'),
            ]);
        }
    }
}
