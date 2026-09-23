<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One unverified score in a leaderboard's verification queue (design §8.3,
 * §9). It does not count until the desk verifies it.
 *
 * `proofUrl` shows an existing proof to the desk; nothing here uploads one
 * (§8.7, #322).
 */
#[TypeScript]
final class DeskScoreEntryData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $entrantId,
        public readonly string $teamName,
        public readonly ?int $heat,
        public readonly float $value,
        public readonly ?string $note,
        public readonly ?string $proofUrl,
        public readonly string $submittedAt,
    ) {}
}
