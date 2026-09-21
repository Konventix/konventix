<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The `messaging.digest` template's payload (ADR-0041 point 5): several
 * held messages for one (recipient, channel) coalesce into one message
 * listing each item's rendered subject, in the recipient's locale.
 *
 * Since Round 4 Task 27 (D-MSG-11) an item is a `{subject, url}` pair
 * rather than a bare subject, so a digested message keeps the link its own
 * payload carried. The digest itself has no single `url` — it points at
 * several things — so an in-app digest row's action URL stays null
 * (`Channels\InAppChannel`).
 */
final class DigestData extends Data
{
    /**
     * @param  list<DigestItemData>  $items  each held message's rendered
     *                                       subject and its own link
     */
    public function __construct(
        public readonly int $count,
        #[DataCollectionOf(DigestItemData::class)]
        public readonly array $items,
    ) {}
}
