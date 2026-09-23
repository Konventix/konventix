<?php

declare(strict_types=1);

namespace Konventix\Admission\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\EntranceEventProfile;

/**
 * Whether a ticket is "in", read the way the door reads it (#329, Task 3
 * review I1): on the **current event day** only. The door has no memory of
 * yesterday — a guest scanned in on day 1 and never scanned out, whose phone
 * dies on day 2, is admitted by the door on day 2 — so the console must not
 * refuse them as "already in" either. Every place that asks "in or not" goes
 * through here: `CheckInFromConsole`'s already-in / not-in refusals, the
 * attendee list's in/out filter and each row's check-in state, and the row
 * actions' enabled state.
 *
 * The day is the one the core resolves (`RecordCheckIn`:
 * `EntranceEventProfile::dayCovering($at)`), and a check-in row records it in
 * `event_day_id`. With no calendar there is no day to scope to, so every
 * accepted row counts. A calendar that has no day for the moment falls back
 * to every row too: the core refuses any check-in or check-out then
 * (`outside_event_day`), so the reading only decides what the list shows,
 * and the latest state is more useful there than "nobody".
 *
 * Plain query builders: `check_ins` is brand-owned, and on the admin host
 * `BrandScope` reads nothing (`.ai/rules/app-modules-src.md`), so the brand
 * is always an explicit condition here.
 */
final class LatestAcceptedCheckIn
{
    /**
     * The event day "in or not" is read on at `$at`, or null for every row.
     */
    public static function dayFor(string $brandId, string $eventId, CarbonInterface $at): ?string
    {
        $profile = EntranceEventProfile::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first();

        return $profile?->dayCovering($at)['day_id'] ?? null;
    }

    /**
     * The direction of one admission's latest accepted check-in on the day,
     * or null when it has none there.
     *
     * @return 'in'|'out'|null
     */
    public static function direction(string $brandId, string $admissionId, ?string $dayId): ?string
    {
        $direction = self::accepted($brandId, $dayId)
            ->where('check_ins.admission_id', $admissionId)
            ->orderByDesc('check_ins.scanned_at')
            ->orderByDesc('check_ins.id')
            ->value('check_ins.direction');

        return match ($direction) {
            'in' => 'in',
            'out' => 'out',
            default => null,
        };
    }

    /**
     * One row per admission of the event that has an accepted check-in on the
     * day: `admission_id`, `direction`, `scanned_at` and the checkpoint's
     * `checkpoint_kind` (null for a row without a checkpoint). A
     * `DISTINCT ON (admission_id)` subquery, for joining into a list.
     */
    public static function perAdmission(string $brandId, string $eventId, ?string $dayId): Builder
    {
        return self::accepted($brandId, $dayId)
            ->leftJoin('checkpoints', 'checkpoints.id', '=', 'check_ins.checkpoint_id')
            ->whereIn('check_ins.admission_id', DB::table('admissions')
                ->select('admissions.id')
                ->where('admissions.brand_id', $brandId)
                ->where('admissions.event_id', $eventId))
            ->selectRaw('distinct on (check_ins.admission_id) check_ins.admission_id, check_ins.direction, check_ins.scanned_at, checkpoints.kind as checkpoint_kind')
            ->orderBy('check_ins.admission_id')
            ->orderByDesc('check_ins.scanned_at')
            ->orderByDesc('check_ins.id');
    }

    private static function accepted(string $brandId, ?string $dayId): Builder
    {
        return DB::table('check_ins')
            ->where('check_ins.brand_id', $brandId)
            ->where('check_ins.result', CheckInResult::Accepted->value)
            ->when($dayId !== null, fn (Builder $query) => $query->where('check_ins.event_day_id', $dayId));
    }
}
