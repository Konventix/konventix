<?php

declare(strict_types=1);

namespace Konventix\Community\Rooms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Konventix\Community\Data\ModerationLineData;
use Konventix\Community\Data\ModerationMuteData;
use Konventix\Community\Data\ModerationReportData;
use Konventix\Community\Data\ModerationRoomData;
use Konventix\Community\Data\ShoutboxModerationData;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;

/**
 * The moderation console's read side (design §8): the open report queue,
 * the mute list, the lock switch and the latest lines of one event's wall.
 *
 * **This runs on the operator admin host**, which resolves no brand and
 * hardens `BrandScope` to zero rows (ADR-0029). Every query therefore
 * escapes the scope and filters by the room's own brand — never ambient
 * context, and never a relation, because a relation re-applies the scope
 * on the related model (`.ai/rules/app-modules-src.md`).
 *
 * **The desk sees what the room does not**: bodies of removed lines and the
 * reason they were removed. That is spec §4.2's "the desk can see what it
 * acted on", and it is why this is a separate reader from `WallReader`,
 * which never hands a removed body to anyone.
 */
final class ModerationDesk
{
    /** How many of the latest lines the desk lists. */
    public const int RECENT_LINES = 50;

    public function for(string $eventId, ?Room $room): ShoutboxModerationData
    {
        if ($room === null) {
            return new ShoutboxModerationData(eventId: $eventId, room: null, reports: [], mutes: [], lines: []);
        }

        $reports = $this->openReports($room);
        $mutes = $this->mutesInForce($room);
        $lines = Message::withoutBrandScope()
            ->forBrand($room->brand_id)
            ->where('room_id', $room->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_LINES)
            ->get();

        $reported = $reports->isEmpty()
            ? collect()
            : Message::withoutBrandScope()
                ->forBrand($room->brand_id)
                ->whereIn('id', $reports->pluck('message_id')->all())
                ->get()
                ->keyBy('id');

        $names = $this->displayNames([
            ...$lines->pluck('author_user_id')->all(),
            ...$reported->pluck('author_user_id')->all(),
            ...$reports->pluck('reporter_user_id')->all(),
            ...$mutes->pluck('user_id')->all(),
        ]);

        return new ShoutboxModerationData(
            eventId: $eventId,
            room: new ModerationRoomData(
                id: $room->id,
                lockedAt: $room->locked_at?->toIso8601String(),
                lockedReason: $room->locked_reason,
                closedAt: $room->closed_at?->toIso8601String(),
            ),
            reports: $reports
                ->map(function (MessageReport $report) use ($reported, $names): ModerationReportData {
                    /** @var Message $message */
                    $message = $reported->get($report->message_id);

                    return new ModerationReportData(
                        id: $report->id,
                        messageId: $message->id,
                        authorUserId: $message->author_user_id,
                        authorDisplayName: $names[$message->author_user_id] ?? null,
                        body: $message->body,
                        removedAt: $message->deleted_at?->toIso8601String(),
                        reporterDisplayName: $names[$report->reporter_user_id] ?? null,
                        note: $report->note,
                        reportedAt: $report->created_at?->toIso8601String() ?? '',
                    );
                })
                ->values()
                ->all(),
            mutes: $mutes
                ->map(fn (RoomMute $mute): ModerationMuteData => new ModerationMuteData(
                    userId: $mute->user_id,
                    displayName: $names[$mute->user_id] ?? null,
                    reason: $mute->reason,
                    expiresAt: $mute->expires_at?->toIso8601String(),
                    mutedAt: $mute->created_at?->toIso8601String() ?? '',
                ))
                ->values()
                ->all(),
            lines: $lines
                ->map(fn (Message $message): ModerationLineData => new ModerationLineData(
                    id: $message->id,
                    authorUserId: $message->author_user_id,
                    authorDisplayName: $names[$message->author_user_id] ?? null,
                    body: $message->body,
                    postedAt: $message->created_at?->toIso8601String() ?? '',
                    removedAt: $message->deleted_at?->toIso8601String(),
                    removedReason: $message->deleted_reason,
                ))
                ->all(),
        );
    }

    /**
     * The queue: open reports on this room's lines, oldest first — the one
     * waiting longest is the one to work next.
     *
     * @return EloquentCollection<int, MessageReport>
     */
    private function openReports(Room $room): EloquentCollection
    {
        return MessageReport::withoutBrandScope()
            ->forBrand($room->brand_id)
            ->where('state', ReportState::Open)
            ->whereIn('message_id', Message::withoutBrandScope()
                ->forBrand($room->brand_id)
                ->where('room_id', $room->id)
                ->select('id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * One row per silenced person: their strongest mute in force, the same
     * ordering `RoomMute::strongestInForce()` uses.
     *
     * @return Collection<int, RoomMute>
     */
    private function mutesInForce(Room $room): Collection
    {
        return RoomMute::withoutBrandScope()
            ->forBrand($room->brand_id)
            ->where('room_id', $room->id)
            ->inForce(CarbonImmutable::now()->utc())
            ->orderBy('user_id')
            ->orderByRaw('expires_at DESC NULLS FIRST')
            ->get()
            ->unique('user_id')
            ->values();
    }

    /**
     * @param  array<int, mixed>  $userIds  as `pluck()` hands them over
     * @return array<string, string>
     */
    private function displayNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, is_string(...))));

        if ($userIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return CommunityProfile::query()->whereIn('id', $userIds)->pluck('display_name', 'id')->all();
    }
}
