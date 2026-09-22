<?php

declare(strict_types=1);

namespace Konventix\Community\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * The shoutbox's retention clock (design §7, D-COM-05): **30 days after
 * the room closed**, its messages, mutes and reports are deleted. The room
 * row survives as a tombstone, so a link to the wall does not 404 into
 * nothing — it opens onto an empty, closed room.
 *
 * `closed_at` is set by `CloseRoomOnEventConcluded` from the event's own
 * conclusion instant; this command only reads it. A room is due once
 * `closed_at <= now - 30 days`, so a room closed at 20:00 on 18 October is
 * swept by the first run at or after 20:00 on 17 November.
 *
 * **Across every brand, and not gated on `community`.** This is a
 * retention obligation on rows that exist, not a feature a brand uses:
 * switching the module off must not freeze a closed wall's personal data
 * past its clock. Brand-owned models are therefore read with
 * `withoutBrandScope()` — the scheduler resolves no brand, and `BrandScope`
 * would otherwise harden to nothing.
 *
 * Reports go first, then messages, then mutes, one room per transaction.
 * The report's foreign key would cascade from the message anyway; deleting
 * them by name keeps the order visible and does not lean on a constraint a
 * later migration could change. A room already swept has nothing left to
 * delete, so a second run — or an overlapping one, which
 * `withoutOverlapping()` in `CommunityServiceProvider::registerSchedule()`
 * prevents regardless — changes nothing.
 */
final class SweepExpiredRoomsCommand extends Command
{
    /**
     * D-COM-05. Public so the provider's docblock, the tests and the
     * operations runbook all name one number.
     */
    public const int RETENTION_DAYS = 30;

    protected $signature = 'community:sweep-shoutbox';

    protected $description = 'Delete the messages, mutes and reports of shoutbox rooms closed more than 30 days ago.';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->utc()->subDays(self::RETENTION_DAYS);

        $swept = 0;
        $messages = 0;

        foreach ($this->dueRoomIds($cutoff) as $roomId) {
            $messages += DB::transaction(function () use ($roomId): int {
                $messageIds = Message::withoutBrandScope()->where('room_id', $roomId)->select('id');

                MessageReport::withoutBrandScope()->whereIn('message_id', $messageIds)->delete();
                $deleted = Message::withoutBrandScope()->where('room_id', $roomId)->delete();
                RoomMute::withoutBrandScope()->where('room_id', $roomId)->delete();

                return $deleted;
            });

            $swept++;
        }

        $this->info("Swept {$swept} shoutbox room(s), {$messages} message(s) deleted.");

        return self::SUCCESS;
    }

    /**
     * Closed rooms past the cutoff that still hold something. Tombstones
     * already swept are skipped by the two `whereIn`s, so the run's cost
     * follows what is left to delete rather than every room ever closed.
     *
     * @return list<string>
     */
    private function dueRoomIds(CarbonInterface $cutoff): array
    {
        /** @var list<string> */
        return Room::withoutBrandScope()
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $cutoff)
            ->where(fn ($query) => $query
                ->whereIn('id', Message::withoutBrandScope()->select('room_id'))
                ->orWhereIn('id', RoomMute::withoutBrandScope()->select('room_id')))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
