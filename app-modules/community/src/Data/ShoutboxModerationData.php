<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The moderation console for one event's wall (design §8): the open report
 * queue, the mute list, the lock switch and the latest lines.
 *
 * `room` is null for an event whose wall nobody has opened yet: rooms are
 * created lazily on a participant's first read (spec §4.1), and the console
 * never creates one — it knows the event only by the id in its URL, so it
 * has no brand to create it under.
 *
 * `muteReasonMaxLength` is `MuteData::MAX_REASON_LENGTH`, handed to the
 * mute form's `maxlength` so the form and the rule cannot drift apart.
 */
#[TypeScript]
final class ShoutboxModerationData extends Data
{
    /**
     * @param  array<int, ModerationReportData>  $reports
     * @param  array<int, ModerationMuteData>  $mutes
     * @param  array<int, ModerationLineData>  $lines
     */
    public function __construct(
        public readonly string $eventId,
        public readonly ?ModerationRoomData $room,

        /** @var array<int, ModerationReportData> */
        #[DataCollectionOf(ModerationReportData::class)]
        public readonly array $reports,

        /** @var array<int, ModerationMuteData> */
        #[DataCollectionOf(ModerationMuteData::class)]
        public readonly array $mutes,

        /** @var array<int, ModerationLineData> */
        #[DataCollectionOf(ModerationLineData::class)]
        public readonly array $lines,
        public readonly int $muteReasonMaxLength = MuteData::MAX_REASON_LENGTH,
    ) {}
}
