<?php

declare(strict_types=1);

namespace Konventix\Staffing\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Events\AssignmentAccepted;
use Konventix\Staffing\Models\Assignment;
use RuntimeException;

/**
 * A lead confirms an offered assignment on an approval-gated team
 * (D-STF-01).
 *
 * Emits `AssignmentAccepted` — the very same event a direct self-service
 * claim emits — so Admission's crew-badge listener has exactly one thing to
 * react to, regardless of which route the person took to get rostered.
 */
final class ConfirmAssignment
{
    public function handle(Assignment $assignment): Assignment
    {
        return DB::transaction(function () use ($assignment): Assignment {
            if ($assignment->state === AssignmentState::Accepted) {
                return $assignment;
            }

            if ($assignment->state !== AssignmentState::Offered) {
                throw new RuntimeException(sprintf(
                    'Assignment [%s] is %s and cannot be confirmed.',
                    $assignment->id,
                    $assignment->state->value,
                ));
            }

            $assignment->forceFill([
                'state' => AssignmentState::Accepted,
                'responded_at' => now(),
            ])->save();

            $role = $assignment->shiftRole()->withoutGlobalScopes()->firstOrFail();
            $shift = $assignment->shift()->withoutGlobalScopes()->firstOrFail();

            event(new AssignmentAccepted(
                assignmentId: $assignment->id,
                shiftId: $shift->id,
                shiftRoleId: $role->id,
                eventId: $shift->event_id,
                brandId: $assignment->brand_id,
                userId: $assignment->user_id,
                role: $role->admission_role,
                startsAt: $shift->starts_at->toIso8601String(),
                endsAt: $shift->ends_at->toIso8601String(),
                acceptedAt: now()->toIso8601String(),
            ));

            return $assignment->refresh();
        });
    }
}
