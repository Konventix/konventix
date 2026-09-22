<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The full room at `/live/shoutbox` (design §8): the wall with more lines
 * than the card, and the presence list beside it. `noteMaxLength` is the
 * report form's own cap (`ReportData::MAX_NOTE_LENGTH`), handed over so the
 * form's `maxlength` cannot drift from the rule that enforces it.
 */
#[TypeScript]
final class ShoutboxRoomData extends Data
{
    /**
     * @param  array<int, ShoutboxPresenceData>  $presence
     */
    public function __construct(
        public readonly ?string $eventName,
        public readonly ShoutboxWallData $wall,

        /** @var array<int, ShoutboxPresenceData> */
        #[DataCollectionOf(ShoutboxPresenceData::class)]
        public readonly array $presence,
        public readonly int $noteMaxLength = ReportData::MAX_NOTE_LENGTH,
    ) {}
}
