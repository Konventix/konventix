<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Proofs\ProofGate;
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
 *
 * **`desk_only` shuts the participant path entirely** (§8.1). The knob is
 * "whether participants may report", and §8.3's score entry is a participant
 * reporting a result; an organizer running a jury-scored contest who set
 * `desk_only` should not find the verification queue filling with numbers
 * typed by the competitors. The desk path (`asOrganizer`) is never refused —
 * "the desk can always record" — and the refusal is here rather than only on
 * the form, because a client that ignores the interface must still be turned
 * away.
 */
final class SubmitScoreEntry
{
    public function __construct(
        private readonly CalculateStandings $standings,
        private readonly ProofGate $proofs,
    ) {}

    public function handle(
        Stage $stage,
        Entrant $entrant,
        ScoreEntryData $data,
        string $submittedByUserId,
        bool $asOrganizer = false,
    ): ScoreEntry {
        return DB::transaction(function () use ($stage, $entrant, $data, $submittedByUserId, $asOrganizer): ScoreEntry {
            $competition = $this->competitionOf($stage);

            $this->guardStage($stage, $entrant);
            $this->guardSubmissionMode($competition, $asOrganizer);
            $this->guardHeat($stage, $data);

            $proofMediaId = $this->proofs->resolve($competition, $data->proofMediaId);

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
                'proof_media_id' => $proofMediaId,
                'submitted_by_user_id' => $submittedByUserId,
                'note' => $data->note,
                // A replacement starts unverified again, whoever wrote it:
                // the desk's own row is verified below, and a participant's
                // resubmission must not inherit the verification of the
                // number it replaced.
                'verified_at' => $asOrganizer ? now() : null,
                'verified_by_user_id' => $asOrganizer ? $submittedByUserId : null,
                // A new number is not the number that was turned down, so
                // the rejection and its reason go with the old one (§8.3).
                'rejected_at' => null,
                'rejected_by_user_id' => null,
                'rejection_note' => null,
            ]);

            // brand_id from the stage, not ambient context (ADR-0010): the
            // desk runs on the operator host, where no brand resolves.
            $entry->forceFill(['brand_id' => $stage->brand_id])->save();

            $this->proofs->recordUsage($proofMediaId, $stage->brand_id, 'score_entry', $entry->id);

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

    /**
     * The competition behind the stage. Null where the row is gone, which
     * `ProofGate` reads as "no policy to apply" rather than as a reason to
     * refuse a score somebody played for.
     */
    private function competitionOf(Stage $stage): ?Competition
    {
        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($stage->competition_id)->first();

        return $competition;
    }

    /**
     * §8.1's first knob (see the class note). The desk is never refused.
     */
    private function guardSubmissionMode(?Competition $competition, bool $asOrganizer): void
    {
        if ($asOrganizer || $competition === null) {
            return;
        }

        if ($competition->result_submission_mode !== ResultSubmissionMode::Participants) {
            throw ResultSubmissionException::deskOnly($competition->id);
        }
    }
}
