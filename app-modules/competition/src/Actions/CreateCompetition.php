<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionIdentity;
use Konventix\Competition\Data\CompetitionData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Stage;

/**
 * Creates a competition **and its first stage** from the console form
 * (design §7.1, §9). One Action because they are one thing to the organizer
 * and because the format only exists on the stage: a competition without a
 * stage has nowhere to keep the format the form just asked for.
 *
 * It always starts in `Draft` (§4). Publishing is a transition with its own
 * Action and its own event, not a checkbox on the create form.
 *
 * The brand comes from the request, not from context: the admin host
 * resolves none (ADR-0029), so `brand_id` is force-filled before the save
 * and `BelongsToBrand`'s creating hook finds it already set. The stage gets
 * the same treatment — a stage created inside an admin-host request would
 * otherwise have no brand to inherit.
 */
final class CreateCompetition
{
    use GuardsCompetitionIdentity;

    public function handle(string $brandId, CompetitionData $data): Competition
    {
        return DB::transaction(function () use ($brandId, $data): Competition {
            $this->guardSlug($brandId, $data->eventId, $data->slug);
            $this->guardDiscipline($brandId, $data->disciplineId);
            $this->guardStageFormat($data);

            $competition = new Competition;
            $competition->forceFill([
                'brand_id' => $brandId,
                'event_id' => $data->eventId,
                'discipline_id' => $data->disciplineId,
                'name' => $data->name,
                'slug' => $data->slug,
                'status' => CompetitionStatus::Draft,
                'description' => $data->description,
                'rules' => $data->rules,
                'max_entrants' => $data->maxEntrants,
                'signup_opens_at' => $data->signupOpensAtInstant(),
                'signup_closes_at' => $data->signupClosesAtInstant(),
                'starts_at' => $data->startsAtInstant(),
                'check_in_mode' => $data->checkInMode,
                'check_in_opens_minutes_before' => $data->checkInOpensMinutesBefore,
                'requires_event_check_in' => $data->requiresEventCheckIn,
                'result_submission_mode' => $data->resultSubmissionMode,
                'proof_policy' => $data->proofPolicy,
                'published_at' => null,
            ])->save();

            (new Stage)->forceFill([
                'brand_id' => $brandId,
                'competition_id' => $competition->getKey(),
                'name' => $data->stageName,
                'format' => $data->stageFormat,
                'sequence' => 1,
                'settings' => $data->stageSettings,
            ])->save();

            return $competition;
        });
    }
}
