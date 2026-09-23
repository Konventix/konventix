<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One side's claim on a disputed match (design §8.2): the score line keyed by
 * slot position, and the proof it carried.
 *
 * `proofUrl` lets the desk **look at** an existing proof. There is no upload
 * anywhere on the desk: a proof is readable by anyone holding its ULID until
 * Platform guards the read (§14, §8.7, #322).
 */
#[TypeScript]
final class DeskReportData extends Data
{
    /**
     * @param  array<int, int|null>  $scores  slot position => score
     */
    public function __construct(
        public readonly string $entrantId,
        public readonly string $teamName,
        public readonly array $scores,
        public readonly ?string $proofUrl,
        public readonly string $reportedAt,
    ) {}
}
