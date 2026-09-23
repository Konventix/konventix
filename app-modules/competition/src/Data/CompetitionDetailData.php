<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The competition itself on its public page (design §10).
 *
 * `proofRequired` is §8.1's `required` policy. The participant pages offer
 * no upload while Platform cannot guard who reads a proof (§8.7, #322), so
 * they say that a proof is owed and that the desk collects it.
 *
 * `requiredLinkedAccount` is the display name of the provider the
 * discipline requires everyone to have linked (design §5.6), or null.
 */
#[TypeScript]
final class CompetitionDetailData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly CompetitionStatus $status,
        /** Description and rules as HTML, rendered by `TournamentPages::markdown()`. */
        public readonly ?string $descriptionHtml,
        public readonly ?string $rulesHtml,
        public readonly string $disciplineName,
        public readonly int $teamSize,
        public readonly ?string $format,
        public readonly int $entrants,
        public readonly ?int $maxEntrants,
        public readonly ?string $startsAt,
        public readonly bool $signupOpen,
        public readonly ResultSubmissionMode $resultSubmissionMode,
        public readonly bool $proofRequired,
        /** The leaderboard's unit and heat count (§7.2), null on a bracket. */
        public readonly ?string $unit,
        public readonly ?int $heats,
        /** The provider every player must have linked (§5.6), e.g. "Steam". */
        public readonly ?string $requiredLinkedAccount = null,
        /** How many decimals the leaderboard prints (§7.2), null on a bracket. */
        public readonly ?int $decimals = null,
    ) {}
}
