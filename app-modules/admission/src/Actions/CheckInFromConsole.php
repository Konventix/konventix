<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Admission\Events\ConsoleCheckInRecorded;
use Konventix\Admission\Exceptions\ConsoleCheckInRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceEventProfile;
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
 * 2. Inside the lock, `in` is refused while the last accepted check-in is
 *    `in`, and `out` while it is not. The door has no such rule, so there is
 *    no door-shaped rejection to record: the refusal throws and writes no row.
 * 3. The door checks the ticket owes today (`OwedDoorChecks`) must all be
 *    answered, `confirmed` or `refused`, and nothing else may be — refused
 *    likewise before any write. A `refused` answer still checks in, as at the
 *    door (ADR-0066 §5, T-8).
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

            $lastDirection = CheckIn::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('admission_id', $locked->id)
                ->where('result', CheckInResult::Accepted)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->value('direction');

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

            $owed = OwedDoorChecks::for($admission, $profile?->dayCovering($at)['day_id'] ?? null);
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
     * comes first so the steady state is a read; the create converges on the
     * partial unique index when two first check-ins race
     * (`firstOrCreate()` is a read followed by `createOrFirst()`, which
     * inserts in a savepoint and re-reads on the violation).
     *
     * `brand_id` is written explicitly — the admission's own, never the
     * ambient context, which the admin host does not have (ADR-0010,
     * ADR-0029) — hence the unguarded create.
     */
    private function consoleCheckpoint(string $brandId, string $eventId): Checkpoint
    {
        return Model::unguarded(static fn (): Checkpoint => Checkpoint::withoutBrandScope()->firstOrCreate(
            [
                'brand_id' => $brandId,
                'event_id' => $eventId,
                'kind' => CheckpointKind::Console->value,
            ],
            ['name' => self::CHECKPOINT_NAME],
        ));
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
