<?php

declare(strict_types=1);

namespace Konventix\Admission\Support;

use Illuminate\Support\Collection;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;

/**
 * The door checks a ticket still owes on an event day (ADR-0066 §6.4,
 * attendee-list design §4.3, D-ATT-04): the `document_at_door` entries of
 * its stamped `entry_conditions` that no accepted check-in of this admission
 * answered on that day — `confirmed` or `refused`, either counts as answered,
 * because a refusal records a fact and still lets the holder in (T-8).
 *
 * `$dayId` is the event day the check-in would land on
 * (`EntranceEventProfile::dayCovering()`). Null means the event has no day
 * calendar, and then an answer given on any earlier check-in counts.
 *
 * Only `document_at_door` conditions: an `operator_checked` one fills a
 * console worklist and a `self_declared` one was answered on the form, the
 * same filter `IssueAdmissionsForRegistration` applies before telling the
 * door. The string is the contract; this module imports nothing of
 * Registration's (ADR-0004).
 *
 * @phpstan-type OwedCheck array{key: string, label: string, documentHint: string|null}
 */
final class OwedDoorChecks
{
    /**
     * Written as a literal because this module does not import
     * `Konventix\Registration\Enums\EntryConditionVerification`: the string
     * is the contract. `IssueAdmissionsForRegistration` filters on this same
     * constant before telling the door.
     */
    public const string VERIFICATION_DOCUMENT_AT_DOOR = 'document_at_door';

    /** The two answers the door accepts (`EntranceController`'s `Rule::in`). */
    public const array OUTCOMES = ['confirmed', 'refused'];

    /**
     * @return list<OwedCheck>
     */
    public static function for(Admission $admission, ?string $dayId): array
    {
        return self::forMany(new Collection([$admission]), $dayId)[$admission->id] ?? [];
    }

    /**
     * One query for a page of the attendee list. Every admission handed in is
     * a key of the result, with an empty list when it owes nothing.
     *
     * @param  Collection<int, Admission>  $admissions
     * @return array<string, list<OwedCheck>>
     */
    public static function forMany(Collection $admissions, ?string $dayId): array
    {
        $result = [];
        $withChecks = [];

        foreach ($admissions as $admission) {
            $checks = self::doorChecksOf($admission);
            $result[$admission->id] = $checks;

            if ($checks !== []) {
                $withChecks[] = $admission;
            }
        }

        if ($withChecks === []) {
            return $result;
        }

        $answered = self::answeredKeys($withChecks, $dayId);

        foreach ($withChecks as $admission) {
            $keys = $answered[$admission->id] ?? [];

            $result[$admission->id] = array_values(array_filter(
                $result[$admission->id],
                static fn (array $check): bool => ! in_array($check['key'], $keys, true),
            ));
        }

        return $result;
    }

    /**
     * @return list<OwedCheck>
     */
    private static function doorChecksOf(Admission $admission): array
    {
        $checks = [];

        foreach ($admission->entry_conditions ?? [] as $condition) {
            if ($condition['verification'] !== self::VERIFICATION_DOCUMENT_AT_DOOR) {
                continue;
            }

            $checks[] = [
                'key' => $condition['key'],
                'label' => $condition['label'],
                'documentHint' => $condition['document_hint'],
            ];
        }

        return $checks;
    }

    /**
     * Keys answered on an accepted check-in, per admission. Queried per brand
     * and without the brand scope, because the console runs on the admin
     * host, where the scope reads nothing (`.ai/rules/app-modules-src.md`).
     *
     * @param  list<Admission>  $admissions
     * @return array<string, list<string>>
     */
    private static function answeredKeys(array $admissions, ?string $dayId): array
    {
        $idsByBrand = [];

        foreach ($admissions as $admission) {
            $idsByBrand[$admission->brand_id][] = $admission->id;
        }

        $answered = [];

        foreach ($idsByBrand as $brandId => $ids) {
            $rows = CheckIn::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereIn('admission_id', $ids)
                ->where('result', CheckInResult::Accepted)
                ->whereNotNull('conditions_checked')
                ->when($dayId !== null, fn ($query) => $query->where('event_day_id', $dayId))
                ->get(['id', 'admission_id', 'conditions_checked']);

            foreach ($rows as $row) {
                foreach ($row->conditions_checked ?? [] as $outcome) {
                    if (in_array($outcome['outcome'], self::OUTCOMES, true)) {
                        $answered[(string) $row->admission_id][] = $outcome['key'];
                    }
                }
            }
        }

        return $answered;
    }
}
