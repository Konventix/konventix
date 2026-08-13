<?php

declare(strict_types=1);

namespace Konventix\Staffing\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Events\AssignmentReleased;
use Konventix\Staffing\Events\ShiftUnderstaffed;
use Konventix\Staffing\Models\Assignment;

/**
 * Gives a shift back — by the staffer or by a lead.
 *
 * Emits `ShiftUnderstaffed` only when the release actually opens a gap. A
 * shift published short was already short; re-announcing that on every
 * unrelated change is how an alert becomes noise. The useful moment to tell a
 * lead is when the roster changed under them.
 *
 * Declining an offer and releasing an accepted shift end in different states
 * on purpose: only the second leaves a hole in a roster people were counting
 * on.
 *
 * Idempotent — releasing twice (a click plus a queued job) emits once.
 */
final class ReleaseAssignment
{
    public function handle(Assignment $assignment, string $reason = 'released'): Assignment
    {
        return DB::transaction(function () use ($assignment, $reason): Assignment {
            if (! $assignment->state->occupiesSlot()) {
                return $assignment;
            }

            $wasAccepted = $assignment->state === AssignmentState::Accepted;

            $assignment->forceFill([
                'state' => $wasAccepted ? AssignmentState::Released : AssignmentState::Declined,
                'responded_at' => now(),
            ])->save();

            $shift = $assignment->shift()->withoutGlobalScopes()->firstOrFail();

            event(new AssignmentReleased(
                assignmentId: $assignment->id,
                shiftId: $shift->id,
                eventId: $shift->event_id,
                brandId: $assignment->brand_id,
                userId: $assignment->user_id,
                reason: $reason,
                releasedAt: now()->toIso8601String(),
            ));

            $role = $assignment->shiftRole()->withoutGlobalScopes()->firstOrFail();
            $filled = $role->filledHeadcount();

            if ($filled < $role->required_headcount) {
                event(new ShiftUnderstaffed(
                    shiftId: $shift->id,
                    shiftRoleId: $role->id,
                    eventId: $shift->event_id,
                    brandId: $assignment->brand_id,
                    requiredHeadcount: $role->required_headcount,
                    acceptedHeadcount: $filled,
                    detectedAt: now()->toIso8601String(),
                ));
            }

            return $assignment->refresh();
        });
    }
}
