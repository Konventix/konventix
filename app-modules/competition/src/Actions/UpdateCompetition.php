<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionIdentity;
use Konventix\Competition\Data\CompetitionData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\CompetitionRescheduled;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;

/**
 * Edits a competition and its first stage (design §7.1, §9).
 *
 * Three things it will not do:
 *
 * - **Move an entered competition to another event.** Teams are per event
 *   (D-CMP-14), so the entrants would end up in a competition their teams do
 *   not belong to — `EnterCompetition` refuses that combination at signup and
 *   an edit must not create it behind its back.
 * - **Touch the lifecycle.** `status` moves through the lifecycle Actions
 *   only (§4).
 * - **Re-format a generated stage.** Once a bracket is drawn, its format is
 *   the shape of the matches that exist; changing the string would leave the
 *   rows behind. `GenerateBracket` refuses a second generation for the same
 *   reason, and a redraw is a deliberate act with its own Action.
 *
 * `starts_at` moving is a fact other modules hold: Agenda's programme
 * renders the block at that time (timetable design §4). It emits
 * `CompetitionRescheduled` — but not for a draft, because nothing outside
 * this module has heard of a draft competition yet.
 */
final class UpdateCompetition
{
    use GuardsCompetitionIdentity;

    public function handle(Competition $competition, CompetitionData $data): Competition
    {
        return DB::transaction(function () use ($competition, $data): Competition {
            $this->guardSlug($competition->brand_id, $data->eventId, $data->slug, exceptId: (string) $competition->getKey());
            $this->guardDiscipline($competition->brand_id, $data->disciplineId);
            $this->guardStageFormat($data);
            $this->guardFrozenEvent($competition, $data);

            $previousStartsAt = $competition->starts_at?->toIso8601String();
            $startsAt = $data->startsAtInstant();

            $competition->forceFill([
                'event_id' => $data->eventId,
                'discipline_id' => $data->disciplineId,
                'name' => $data->name,
                'slug' => $data->slug,
                'description' => $data->description,
                'rules' => $data->rules,
                'max_entrants' => $data->maxEntrants,
                'signup_opens_at' => $data->signupOpensAtInstant(),
                'signup_closes_at' => $data->signupClosesAtInstant(),
                'starts_at' => $startsAt,
                'check_in_mode' => $data->checkInMode,
                'check_in_opens_minutes_before' => $data->checkInOpensMinutesBefore,
                'requires_event_check_in' => $data->requiresEventCheckIn,
                'result_submission_mode' => $data->resultSubmissionMode,
                'proof_policy' => $data->proofPolicy,
            ])->save();

            $this->syncFirstStage($competition, $data);

            $moved = $previousStartsAt !== $startsAt?->toIso8601String();
            $announced = $competition->status !== CompetitionStatus::Draft;

            if ($moved && $announced) {
                DB::afterCommit(static function () use ($competition, $previousStartsAt, $startsAt): void {
                    event(new CompetitionRescheduled(
                        competitionId: $competition->id,
                        eventId: $competition->event_id,
                        brandId: $competition->brand_id,
                        previousStartsAt: $previousStartsAt,
                        startsAt: $startsAt?->toIso8601String(),
                        at: now()->toIso8601String(),
                    ));
                });
            }

            return $competition;
        });
    }

    /**
     * The first stage carries the format (§7.1). A competition created
     * before this slice may have none; the form still edits one, so it is
     * created rather than refused.
     */
    private function syncFirstStage(Competition $competition, CompetitionData $data): void
    {
        $stage = Stage::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->orderBy('sequence')
            ->first();

        if ($stage === null) {
            (new Stage)->forceFill([
                'brand_id' => $competition->brand_id,
                'competition_id' => $competition->getKey(),
                'name' => $data->stageName,
                'format' => $data->stageFormat,
                'sequence' => 1,
                'settings' => $data->stageSettings,
            ])->save();

            return;
        }

        if ($stage->isGenerated() && ($stage->format !== $data->stageFormat)) {
            throw ValidationException::withMessages([
                'stageFormat' => __('This bracket is already drawn; its format cannot change.'),
            ]);
        }

        $stage->forceFill([
            'name' => $data->stageName,
            'format' => $data->stageFormat,
            'settings' => $data->stageSettings,
        ])->save();
    }

    private function guardFrozenEvent(Competition $competition, CompetitionData $data): void
    {
        if ($competition->event_id === $data->eventId) {
            return;
        }

        $entered = Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->exists();

        if ($entered) {
            throw ValidationException::withMessages([
                'eventId' => __('Teams have already entered; this competition stays with its event.'),
            ]);
        }
    }
}
