<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Admission\Events\ConsoleCheckInRecorded;
use Konventix\Admission\Exceptions\ConsoleCheckInRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Support\LatestAcceptedCheckIn;
use Konventix\Admission\Support\OwedDoorChecks;
use Konventix\Platform\Brands\BrandContext;

/**
 * Checks a ticket in or out from the operator console (attendee-list design
 * §3.1, D-ATT-08): a guest whose QR code does not work is found by name and
 * let in by an admin, through the same core the door uses, so every consumer
 * of `AdmissionCheckedIn` reacts exactly as to a scan.
 *
 * It never touches a token. What it adds to the core, in one transaction:
 *
 * 1. The admission is re-read **by event** and locked — brand and event
 *    scoped, the admission's own brand. The door gets this guarantee from the
 *    token, which names its event; the console has only a URL, so a ticket of
 *    another event is refused (`wrong_event`) before anything is written.
 * 2. Inside the lock, `in` is refused while the last accepted check-in of
 *    the current event day is `in`, and `out` while it is not
 *    (`Support\LatestAcceptedCheckIn`). The door has no such rule, so there is
 *    no door-shaped rejection to record: the refusal throws and writes no row.
 * 3. The door checks the ticket owes today (`OwedDoorChecks`) must all be
 *    answered, `confirmed` or `refused`, and nothing else may be — refused
 *    likewise before any write. A `refused` answer still checks in, as at the
 *    door (ADR-0066 §5, T-8). A ticket the core will refuse for its day or
 *    its validity owes nothing, so the core's door-equivalent rejection is
 *    what gets written.
 * 4. The event's console checkpoint is found or created (one per event, a
 *    partial unique index), and the core writes the row with a fresh
 *    `console-` scan id, no device, and the acting admin. Every door rule —
 *    day, validity, revocation, re-entry — is the core's, and a refusal from
 *    it is a rejected `CheckIn` plus `CheckInRejected`, exactly as at the
 *    door; the caller turns its `reason_code` into
 *    `CheckInRejectionReason::consoleSentence()`.
 * 5. `ConsoleCheckInRecorded`, the auditable record of who did it, goes out
 *    after the outermost commit (`.ai/rules/src.md`), with whatever brand was
 *    ambient restored around it.
 */
final class CheckInFromConsole
{
    public const string CHECKPOINT_NAME = 'Console';

    public function __construct(
        private readonly RecordCheckIn $recordCheckIn,
        private readonly EvaluateAdmissionValidity $evaluateValidity,
    ) {}

    /**
     * @param  list<array{key: string, outcome: string}>  $conditionsChecked
     *
     * @throws ConsoleCheckInRefusedException
     */
    public function handle(
        Admission $admission,
        string $eventId,
        string $actorUserId,
        string $direction,
        array $conditionsChecked = [],
        ?CarbonInterface $at = null,
    ): CheckIn {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException("A console check-in goes 'in' or 'out', not '{$direction}'.");
        }

        $at ??= now();
        $brandId = $admission->brand_id;

        return DB::transaction(function () use ($admission, $brandId, $eventId, $actorUserId, $direction, $conditionsChecked, $at): CheckIn {
            $locked = Admission::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->whereKey($admission->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ConsoleCheckInRefusedException::wrongEvent(CheckInRejectionReason::WrongEvent->consoleSentence());
            }

            // "In" is read on the event day the check-in lands on, as the
            // door reads it (Task 3 review I1): in on day 1 and never out
            // does not make a guest "already in" on day 2.
            $lastDirection = LatestAcceptedCheckIn::direction(
                $brandId,
                $locked->id,
                LatestAcceptedCheckIn::dayFor($brandId, $eventId, $at),
            );

            if ($direction === 'in' && $lastDirection === 'in') {
                throw ConsoleCheckInRefusedException::alreadyIn();
            }

            if ($direction === 'out' && $lastDirection !== 'in') {
                throw ConsoleCheckInRefusedException::notIn();
            }

            $answers = $this->answers($locked, $brandId, $eventId, $direction, $conditionsChecked, $at);

            $checkIn = $this->recordCheckIn->handle(
                admission: $locked,
                eventId: $eventId,
                clientScanId: 'console-'.Str::ulid(),
                at: $at,
                checkpoint: $this->consoleCheckpoint($brandId, $eventId),
                device: null,
                direction: $direction,
                conditionsChecked: $answers,
                actorUserId: $actorUserId,
            );

            $this->announce(new ConsoleCheckInRecorded(
                checkInId: $checkIn->id,
                admissionId: $locked->id,
                eventId: $eventId,
                brandId: $brandId,
                actorUserId: $actorUserId,
                direction: $direction,
                result: $checkIn->result->value,
                reasonCode: $checkIn->reason_code,
            ));

            return $checkIn;
        });
    }

    /**
     * The answers, checked against what the ticket owes on the day the
     * check-in lands on. A check-out owes nothing, so any answer to one is an
     * unknown key.
     *
     * A ticket the core is about to refuse owes nothing either (Task 3 review
     * M2): when no event day covers the moment, or the admission fails
     * validity (revoked, outside its window), the answers are not asked for
     * and not checked, and the core writes the door's own rejection —
     * `outside_event_day` or `admission_not_valid` — rather than the console
     * asking for a document it would then refuse anyway. A rejected row
     * never carries answers, so nothing is lost by dropping them.
     *
     * @param  list<array{key: string, outcome: string}>  $conditionsChecked
     * @return list<array{key: string, outcome: string}>
     */
    private function answers(Admission $admission, string $brandId, string $eventId, string $direction, array $conditionsChecked, CarbonInterface $at): array
    {
        $owed = [];

        if ($direction === 'in') {
            $profile = EntranceEventProfile::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('event_id', $eventId)
                ->first();

            $dayId = $profile?->dayCovering($at)['day_id'] ?? null;
            $outsideEveryDay = $profile !== null && ($profile->days ?? []) !== [] && $dayId === null;

            if ($outsideEveryDay || ! $this->evaluateValidity->handle($admission, $at)) {
                return [];
            }

            $owed = OwedDoorChecks::for($admission, $dayId);
        }

        $owedKeys = array_column($owed, 'key');
        $answers = [];

        foreach ($conditionsChecked as $answer) {
            $key = $answer['key'];
            $outcome = $answer['outcome'];

            if (! in_array($key, $owedKeys, true)
                || array_key_exists($key, $answers)
                || ! in_array($outcome, OwedDoorChecks::OUTCOMES, true)) {
                throw ConsoleCheckInRefusedException::checksInvalid();
            }

            $answers[$key] = ['key' => $key, 'outcome' => $outcome];
        }

        $unanswered = array_values(array_filter(
            $owed,
            static fn (array $check): bool => ! array_key_exists($check['key'], $answers),
        ));

        if ($unanswered !== []) {
            throw ConsoleCheckInRefusedException::checksUnanswered(array_column($unanswered, 'label'));
        }

        return array_values($answers);
    }

    /**
     * The event's one console checkpoint, created on first use. The lookup
     * comes first so the steady state is a read. The insert runs in its own
     * savepoint, so when two first check-ins race and the partial unique
     * index refuses the second, only that savepoint aborts, and the loser
     * re-reads the winner's row (the shape of Community's `EventRooms`).
     *
     * `brand_id` is written with `forceFill`, never mass-assigned (ADR-0010):
     * the admission's own brand, not the ambient context, which the admin
     * host does not have (ADR-0029).
     */
    private function consoleCheckpoint(string $brandId, string $eventId): Checkpoint
    {
        $existing = $this->findConsoleCheckpoint($brandId, $eventId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(static function () use ($brandId, $eventId): Checkpoint {
                $checkpoint = new Checkpoint([
                    'event_id' => $eventId,
                    'name' => self::CHECKPOINT_NAME,
                    'kind' => CheckpointKind::Console,
                ]);
                $checkpoint->forceFill(['brand_id' => $brandId])->save();

                return $checkpoint;
            });
        } catch (UniqueConstraintViolationException $collision) {
            return $this->findConsoleCheckpoint($brandId, $eventId) ?? throw $collision;
        }
    }

    private function findConsoleCheckpoint(string $brandId, string $eventId): ?Checkpoint
    {
        return Checkpoint::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('kind', CheckpointKind::Console->value)
            ->first();
    }

    private function announce(ConsoleCheckInRecorded $event): void
    {
        $brand = app(BrandContext::class)->current();

        DB::afterCommit(static function () use ($event, $brand): void {
            if ($brand === null) {
                event($event);

                return;
            }

            app(BrandContext::class)->withBrand($brand, static function () use ($event): void {
                event($event);
            });
        });
    }
}
