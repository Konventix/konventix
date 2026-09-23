<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who is looking at a competition page, and which calls to action that
 * leaves them (design §10: "the entry / interest / join-by-code
 * call-to-actions for a logged-in eligible person").
 *
 * Every flag is a courtesy to the page. The Actions behind the buttons
 * decide again, and a refusal comes back as a form error.
 */
#[TypeScript]
final class CompetitionViewerData extends Data
{
    /**
     * @param  array<int, TeamOptionData>  $enterableTeams
     */
    public function __construct(
        public readonly bool $signedIn,
        /** A confirmed registration for the event, or a ticket in one's own name for it (§5.1 and its #330 addendum). */
        public readonly bool $eligible,
        /** The name of the viewer's team in this competition, if it is in. */
        public readonly ?string $enteredTeamName,

        /** @var array<int, TeamOptionData> teams the viewer captains and could enter */
        #[DataCollectionOf(TeamOptionData::class)]
        public readonly array $enterableTeams,
        public readonly bool $interested,
        /** A leaderboard score form for the viewer's own entrant (§8.3). */
        public readonly bool $canSubmitScore,
        /**
         * The discipline requires a linked account (§5.6) and the viewer has
         * not linked it, so the page links to the account settings.
         */
        public readonly bool $missingLinkedAccount = false,
    ) {}
}
