<?php

declare(strict_types=1);

namespace Konventix\Staffing\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Events\AssignmentAccepted;
use Konventix\Staffing\Exceptions\ShiftRoleUnavailableException;
use Konventix\Staffing\Models\Assignment;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\ShiftRole;

/**
 * Self-service sign-up for a shift role (D-STF-01).
 *
 * **A claim on an open team is accepted outright, not offered.** D-STF-01
 * settles self-service *with* per-team approval as the opt-in, so making
 * every volunteer wait for a rubber stamp would invert the decision. A team
 * that sets `requires_approval` gets `offered` instead, and a lead confirms.
 *
 * Either way the slot is occupied immediately: an outstanding offer is a slot
 * a lead must not fill twice while waiting for an answer, which is why
 * `AssignmentState::occupiesSlot()` counts both.
 *
 * Double sign-up is prevented by a partial unique index rather than a check,
 * because a double-tap on a phone at a venue is exactly the race a
 * read-then-write loses — and a coverage count that silently overstates who
 * is coming is worse than a rejected tap.
 */
final class ClaimShiftRole
{
    public function handle(ShiftRole $shiftRole, string $userId): Assignment
    {
        return DB::transaction(function () use ($shiftRole, $userId): Assignment {
            $shift = $shiftRole->shift()->withoutGlobalScopes()->firstOrFail();

            if (! $shift->isPublished()) {
                throw ShiftRoleUnavailableException::notPublished($shift->id);
            }

            // Locked so two concurrent claims cannot both read the same
            // remaining headcount and both pass.
            $locked = ShiftRole::withoutBrandScope()
                ->whereKey($shiftRole->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isFull()) {
                throw ShiftRoleUnavailableException::full($locked->id, $locked->required_headcount);
            }

            $team = $shift->team()->withoutGlobalScopes()->firstOrFail();
            $approvalNeeded = $team->requires_approval;

            $assignment = new Assignment([
                'shift_id' => $shift->id,
                'shift_role_id' => $locked->id,
                'user_id' => $userId,
                'state' => $approvalNeeded ? AssignmentState::Offered : AssignmentState::Accepted,
                'offered_at' => $approvalNeeded ? now() : null,
                'responded_at' => $approvalNeeded ? null : now(),
            ]);
            // brand_id from the shift role, not ambient context (ADR-0010).
            $assignment->forceFill(['brand_id' => $locked->brand_id]);

            try {
                $assignment->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'assignments_one_live_per_person')) {
                    throw ShiftRoleUnavailableException::alreadySignedUp($locked->id, $userId);
                }

                throw $e;
            }

            if (! $approvalNeeded) {
                $this->announceAcceptance($assignment, $locked, $shift);
            }

            return $assignment;
        });
    }

    /**
     * D-STF-01's contract: Staffing emits, Admission listens, and Staffing
     * never writes to Admission directly.
     */
    private function announceAcceptance(Assignment $assignment, ShiftRole $role, Shift $shift): void
    {
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
    }
}
