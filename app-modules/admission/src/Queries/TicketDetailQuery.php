<?php

declare(strict_types=1);

namespace Konventix\Admission\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Konventix\Admission\Data\AttendeeRowData;
use Konventix\Admission\Data\CheckInHistoryRowData;
use Konventix\Admission\Data\TicketDetailData;
use Konventix\Admission\Data\TicketDoorCheckData;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Admission\Models\RegistrationSummary;
use Konventix\Admission\Support\LatestAcceptedCheckIn;
use Konventix\Admission\Support\OwedDoorChecks;
use Konventix\Admission\Support\TicketReference;

/**
 * One ticket for the ticket detail page (attendee-list design §4.4, #329).
 *
 * Runs on the admin host, where `BrandScope` reads nothing, so every
 * brand-owned read names the admission's own brand explicitly
 * (`.ai/rules/app-modules-src.md`). A fixed handful of queries whatever the
 * history's length: the rows, then their checkpoints, devices and people in
 * one `whereIn` each (`Model::shouldBeStrict()`).
 *
 * Whether the ticket can be checked in or out is the attendee list's rule,
 * not a copy of it: `LatestAcceptedCheckIn` on the event day that covers
 * `$now`, `AttendeeRowData::admits()`, and `OwedDoorChecks` for that day.
 */
final class TicketDetailQuery
{
    public function handle(Admission $admission, CarbonInterface $now, bool $canPrintBadge): TicketDetailData
    {
        $brandId = $admission->brand_id;

        $rows = CheckIn::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('admission_id', $admission->id)
            ->orderBy('scanned_at')
            ->orderBy('id')
            ->get();

        $checkpoints = Checkpoint::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('id', $rows->pluck('checkpoint_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $devices = EntranceDevice::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('id', $rows->pluck('device_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $people = Person::query()
            ->whereIn('id', $rows->pluck('actor_user_id')
                ->push($admission->holder_user_id, $admission->buyer_user_id)
                ->filter()
                ->unique()
                ->values())
            ->get()
            ->keyBy('id');

        $summary = $admission->registration_id === null ? null : RegistrationSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $admission->registration_id)
            ->first();

        $event = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $admission->event_id)
            ->first();

        $day = LatestAcceptedCheckIn::day($brandId, $admission->event_id, $now);
        $direction = LatestAcceptedCheckIn::direction($brandId, $admission->id, $day->dayId);
        // The list's own rule (`AttendeeRowData::actions()`), so the page and
        // the list cannot disagree about what the console offers.
        $actions = AttendeeRowData::actions($admission->state, $direction, $day->outsideEveryDay, OwedDoorChecks::for($admission, $day->dayId));

        $holder = $admission->holder_user_id === null ? null : $people->get($admission->holder_user_id);
        $buyer = $admission->buyer_user_id === null ? null : $people->get($admission->buyer_user_id);
        $registrationId = $admission->registration_id;

        return new TicketDetailData(
            admissionId: $admission->id,
            reference: TicketReference::of($admission->id),
            state: $admission->state->value,
            role: $admission->role,
            validFrom: $admission->valid_from?->toIso8601String(),
            validUntil: $admission->valid_until?->toIso8601String(),
            eventId: $admission->event_id,
            eventName: $event->name ?? $admission->event_id,
            holderUserId: $admission->holder_user_id,
            holderName: $holder?->display_name,
            holderUsername: $holder?->username,
            buyerUserId: $admission->buyer_user_id,
            buyerName: $buyer?->display_name,
            buyerUsername: $buyer?->username,
            ticketType: $summary->type_name ?? $admission->role,
            registrationId: $registrationId,
            registrationShortId: $registrationId === null ? null : strtoupper(substr($registrationId, -8)),
            registrationTypeName: $summary?->type_name,
            registrationStatus: $summary?->status,
            doorChecks: $this->doorChecks($admission, $rows),
            history: array_values($rows->map(fn (CheckIn $row): CheckInHistoryRowData => $this->historyRow($row, $checkpoints, $devices, $people))->all()),
            badgeUrl: $canPrintBadge ? route('admin.admissions.badge', ['admission' => $admission->id], absolute: false) : null,
            canCheckIn: $actions['canCheckIn'],
            canCheckOut: $actions['canCheckOut'],
            owedDoorChecks: $actions['owedDoorChecks'],
        );
    }

    /**
     * Every stamped condition with the latest outcome an accepted row
     * recorded for it, on any day: the rows are oldest first, so a later
     * answer overwrites an earlier one.
     *
     * @param  Collection<int, CheckIn>  $rows
     * @return list<TicketDoorCheckData>
     */
    private function doorChecks(Admission $admission, Collection $rows): array
    {
        $latest = [];

        foreach ($rows as $row) {
            if ($row->result !== CheckInResult::Accepted) {
                continue;
            }

            foreach ($row->conditions_checked ?? [] as $answer) {
                if (in_array($answer['outcome'], OwedDoorChecks::OUTCOMES, true)) {
                    $latest[$answer['key']] = $answer['outcome'];
                }
            }
        }

        return array_map(
            static fn (array $condition): TicketDoorCheckData => new TicketDoorCheckData(
                key: $condition['key'],
                label: $condition['label'],
                verification: $condition['verification'],
                documentHint: $condition['document_hint'],
                outcome: $latest[$condition['key']] ?? null,
            ),
            $admission->entry_conditions ?? [],
        );
    }

    /**
     * @param  Collection<string, Checkpoint>  $checkpoints
     * @param  Collection<string, EntranceDevice>  $devices
     * @param  Collection<string, Person>  $people
     */
    private function historyRow(CheckIn $row, Collection $checkpoints, Collection $devices, Collection $people): CheckInHistoryRowData
    {
        $checkpoint = $row->checkpoint_id === null ? null : $checkpoints->get($row->checkpoint_id);
        $device = $row->device_id === null ? null : $devices->get($row->device_id);
        $actor = $row->actor_user_id === null ? null : $people->get($row->actor_user_id);
        $rejected = $row->result === CheckInResult::Rejected;

        return new CheckInHistoryRowData(
            id: $row->id,
            scannedAt: $row->scanned_at->toIso8601String(),
            direction: $row->direction === 'out' ? 'out' : 'in',
            result: $row->result->value,
            reason: $rejected
                ? (CheckInRejectionReason::tryFrom((string) $row->reason_code)?->consoleSentence() ?? $row->reason_code)
                : null,
            source: $checkpoint?->kind === CheckpointKind::Console ? 'console' : 'door',
            checkpointName: $checkpoint?->name,
            deviceLabel: $device?->label,
            actorUserId: $row->actor_user_id,
            actorName: $actor?->display_name,
        );
    }
}
