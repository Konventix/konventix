<?php

declare(strict_types=1);

namespace Konventix\Staffing\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Staffing\Models\Assignment;

/**
 * Staffing's contribution to GDPR export and deletion.
 *
 * Export is the substantive half: someone asking what an operator knows about
 * them is entitled to see the shifts they worked. Deletion has nothing to
 * scrub — an assignment holds a `user_id` and a state, and that ULID is a
 * reference to Identity's tombstone rather than personal data of its own
 * (Round 6 D-BATCH). Deleting the rows would erase the crew roster of an
 * event that already happened.
 *
 * Registered unconditionally like every other module hook (ADR-0003), even
 * though Staffing is Pennant-gated: whether a brand runs the module today has
 * no bearing on whether rows it wrote yesterday must appear in an export.
 */
final class StaffingGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'staffing';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        return [
            'assignments' => Assignment::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Assignment $assignment): array => [
                    'shift_id' => $assignment->shift_id,
                    'shift_role_id' => $assignment->shift_role_id,
                    'state' => $assignment->state->value,
                    'offered_at' => $assignment->offered_at?->toIso8601String(),
                    'responded_at' => $assignment->responded_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        // Nothing to scrub — see the class docblock. Declared rather than
        // omitted so the fan-out records a real answer from this module.
    }
}
