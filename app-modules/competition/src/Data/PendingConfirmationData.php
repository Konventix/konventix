<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A result the other side reported and the viewer's side has not answered
 * yet (design §10, "pending reports to confirm" on `/my/tournaments`). The
 * link goes to the match page, where confirm, counter-report and dispute
 * live; `eventSlug` is null for an event this module has not been told the
 * slug of, and then there is no page to link.
 */
#[TypeScript]
final class PendingConfirmationData extends Data
{
    /**
     * @param  array<int, int|null>  $scores  slot position => score, as the other side claims it
     */
    public function __construct(
        public readonly ?string $eventSlug,
        public readonly string $competitionSlug,
        public readonly string $competitionName,
        public readonly string $matchId,
        public readonly string $opponentName,
        public readonly array $scores,
    ) {}
}
