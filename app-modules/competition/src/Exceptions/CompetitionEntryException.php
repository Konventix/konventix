<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use Konventix\Competition\Enums\LinkedProvider;
use RuntimeException;

/**
 * Thrown when a team cannot enter a competition. Named causes because a
 * player told "you cannot sign up" deserves to know whether signup is shut,
 * they are already in, or they hold no confirmed registration.
 *
 * **Two audiences, two languages** (ADR-0001). The message is English, for
 * the console, the log and the tests. Every cause a participant can reach
 * also carries a German sentence, `participantMessage()`, which the
 * participant controllers show instead. That sentence names no ULID and no
 * design section. A cause only the console reaches has no German sentence
 * and falls back to a generic one, so a participant never reads English even
 * if one of them turns up on their side later.
 *
 * The join form stays opaque: `unknownInviteCode()` answers with
 * `joinRefusal()`, the same sentence `TeamJoinController` gives for every
 * other refusal of a join, so the form cannot tell a guesser whether a code
 * exists.
 */
final class CompetitionEntryException extends RuntimeException
{
    /**
     * The German sentence for a participant, or null for a console-only
     * cause.
     */
    private ?string $participantMessage = null;

    /**
     * Set only by `linkedAccountMissing()`, so that a caller can answer that
     * one refusal in its own words (a German sentence and a link on a
     * participant page) without parsing the message.
     */
    private ?LinkedProvider $missingProvider = null;

    /** @var list<string> */
    private array $missingUserIds = [];

    /**
     * The people who have not linked the provider a competition's discipline
     * requires (design §5.6). On entry these are every roster member without
     * the link; on a join, the one person joining.
     *
     * @param  list<string>  $userIds
     */
    public static function linkedAccountMissing(array $userIds, LinkedProvider $provider, string $competitionId): self
    {
        $exception = new self(sprintf(
            'User(s) [%s] have not linked a %s account, which competition [%s] requires of everyone on its '
            .'rosters (design §5.6).',
            implode(', ', $userIds),
            $provider->label(),
            $competitionId,
        ));

        $exception->missingProvider = $provider;
        $exception->missingUserIds = $userIds;
        // The participant controllers answer this cause in their own words,
        // with names and the settings link; this is the fallback.
        $exception->participantMessage = __('Für dieses Turnier brauchen alle im Team ein verknüpftes :provider-Konto.', [
            'provider' => $provider->label(),
        ]);

        return $exception;
    }

    /**
     * The provider this refusal is about, or null when the refusal has a
     * different cause.
     */
    public function missingLinkedProvider(): ?LinkedProvider
    {
        return $this->missingProvider;
    }

    /**
     * @return list<string>
     */
    public function usersMissingTheLink(): array
    {
        return $this->missingUserIds;
    }

    /**
     * What a participant is told (ADR-0001): the cause in German, or a
     * generic German sentence for a cause only the console should reach.
     */
    public function participantMessage(): string
    {
        return $this->participantMessage
            ?? __('Das hat nicht geklappt. Lade die Seite neu und versuch es noch einmal.');
    }

    /**
     * The one answer the join form gives to every refusal but the
     * linked-account rule (design §5.2, §5.6). It says what the possible
     * reasons are without saying which one applies, so a guessed code tells
     * the guesser nothing.
     */
    public static function joinRefusal(): string
    {
        return __('Dieser Code passt nicht — er ist unbekannt, das Team ist voll, du bist schon dabei, '
            .'oder du bist für diese Veranstaltung nicht angemeldet.');
    }

    /**
     * A refusal a participant can reach: the English message for the console
     * and the log, the German one for the participant's page.
     */
    private static function refusal(string $message, string $participantMessage): self
    {
        $exception = new self($message);
        $exception->participantMessage = $participantMessage;

        return $exception;
    }

    public static function signupClosed(string $competitionId): self
    {
        return self::refusal(
            sprintf('Signup for competition [%s] is not open.', $competitionId),
            __('Die Anmeldung für dieses Turnier ist nicht geöffnet.'),
        );
    }

    public static function alreadyEntered(string $competitionId, string $teamId): self
    {
        return self::refusal(
            sprintf('Team [%s] is already entered in competition [%s].', $teamId, $competitionId),
            __('Euer Team ist bei diesem Turnier schon angemeldet.'),
        );
    }

    public static function noConfirmedRegistration(string $userId, string $eventId): self
    {
        return self::refusal(
            sprintf(
                'User [%s] holds no confirmed registration for event [%s]; a confirmed registration is '
                .'what entitles someone to enter (D-CMP-05).',
                $userId,
                $eventId,
            ),
            __('Dafür braucht es eine bestätigte Anmeldung zur Veranstaltung – für dich und für alle im Team.'),
        );
    }

    public static function teamsAtDifferentEvents(string $fromTeamId, string $toTeamId): self
    {
        return new self(sprintf(
            'Teams [%s] and [%s] belong to different events; a team is per event (D-CMP-14), so there is '
            .'no move between them.',
            $fromTeamId,
            $toTeamId,
        ));
    }

    public static function competitionFull(string $competitionId, int $maxEntrants): self
    {
        return self::refusal(
            sprintf(
                'Competition [%s] already holds its limit of %d entrants; an incomplete roster holds a slot '
                .'too until it is filled or declined (design §5.4).',
                $competitionId,
                $maxEntrants,
            ),
            __('Dieses Turnier ist voll: alle :max Plätze sind vergeben.', ['max' => (string) $maxEntrants]),
        );
    }

    public static function emptyRoster(string $teamId): self
    {
        return self::refusal(
            sprintf('Team [%s] has nobody on it; an empty roster cannot enter anything.', $teamId),
            __('In diesem Team ist niemand. Ein leeres Team kann nicht antreten.'),
        );
    }

    public static function unknownInviteCode(string $code): self
    {
        return self::refusal(
            sprintf('No team of this event uses the invite code [%s].', $code),
            self::joinRefusal(),
        );
    }

    public static function alreadyOnTeam(string $userId, string $teamId): self
    {
        return self::refusal(
            sprintf('User [%s] is already on team [%s].', $userId, $teamId),
            __('Du spielst bei diesem Turnier schon in einem Team mit.'),
        );
    }

    public static function rosterFull(
        string $teamId,
        string $competitionId,
        int $teamSize,
        int $rosterSize,
    ): self {
        return self::refusal(
            sprintf(
                'Team [%s] would field %d player(s), and competition [%s] allows %d — there are no substitutes '
                .'in this slice (design §5.2). The bound is per competition: a roster is refused when any '
                .'contest the team holds a slot in could not field it.',
                $teamId,
                $rosterSize,
                $competitionId,
                $teamSize,
            ),
            __('Das Team wäre mit :count Leuten zu groß: in einem seiner Turniere spielen höchstens :size pro Team.', [
                'count' => (string) $rosterSize,
                'size' => (string) $teamSize,
            ]),
        );
    }

    public static function notOnTeam(string $userId, string $teamId): self
    {
        return new self(sprintf('User [%s] is not on team [%s].', $userId, $teamId));
    }

    public static function notTheCaptain(string $userId, string $teamId): self
    {
        return self::refusal(
            sprintf('User [%s] does not captain team [%s].', $userId, $teamId),
            __('Das darf nur der Captain des Teams.'),
        );
    }

    public static function entrantNotIncomplete(string $entrantId): self
    {
        return new self(sprintf(
            'Entrant [%s] is not incomplete; only an incomplete roster is filled up or declined (design §5.4).',
            $entrantId,
        ));
    }

    public static function entrantAlreadyLeft(string $entrantId): self
    {
        return self::refusal(
            sprintf('Entrant [%s] has already withdrawn or been disqualified.', $entrantId),
            __('Diese Anmeldung ist schon zurückgezogen oder ausgeschlossen.'),
        );
    }

    public static function competitionOver(string $competitionId): self
    {
        // Also what a captain's withdrawal hears once the competition is
        // `Running` (`WithdrawEntrant`), which is why the German sentence
        // covers "has begun" as well as "is over".
        return self::refusal(
            sprintf(
                'Competition [%s] is finished or cancelled; its field is history and does not change.',
                $competitionId,
            ),
            __('Dieses Turnier hat schon begonnen oder ist vorbei. Ändern kann das jetzt nur noch die Turnierleitung.'),
        );
    }

    public static function wrongEvent(string $competitionId, string $teamId): self
    {
        return self::refusal(
            sprintf(
                'Team [%s] belongs to a different event than competition [%s]; teams are per event (D-CMP-14).',
                $teamId,
                $competitionId,
            ),
            __('Dieses Team gehört zu einer anderen Veranstaltung als das Turnier.'),
        );
    }

    public static function selfServiceCheckInClosed(string $competitionId): self
    {
        return self::refusal(
            sprintf(
                'Competition [%s] is not accepting a self-service check-in right now: either the mode is not '
                .'`self_service`, or the window before `starts_at` has not opened or has closed at start '
                .'(design §6). The desk can always check a team in.',
                $competitionId,
            ),
            __('Selbst einchecken geht bei diesem Turnier gerade nicht. Die Turnierleitung kann euch jederzeit einchecken.'),
        );
    }

    public static function entrantNotCheckable(string $entrantId, string $state): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] is `%s`; only a registered entrant is checked in — an incomplete roster is '
                .'resolved first (design §5.4), and one that left the field does not come back.',
                $entrantId,
                $state,
            ),
            __('Diese Anmeldung kann nicht eingecheckt werden: eingecheckt wird nur ein vollständiges Team, das noch im Turnier ist.'),
        );
    }

    public static function entrantNotCheckedIn(string $entrantId): self
    {
        return new self(sprintf('Entrant [%s] is not checked in; there is nothing to undo.', $entrantId));
    }

    /**
     * The missing people are named, not identified. This message is shown to
     * whoever pressed the button — a desk with a queue behind it, or a
     * captain on `/my/tournaments` — so the caller resolves display names
     * through `ParticipantProfile::displayNamesFor()` first: a ULID on screen
     * tells nobody who is still outside, and is a handle to probe at worst.
     *
     * @param  list<string>  $missingNames  Display names, in roster order.
     */
    public static function rosterNotAtTheDoor(string $entrantId, array $missingNames): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] cannot be checked in: this competition requires every roster member at the '
                .'event door, and %d of them (%s) have not been scanned in (design §6).',
                $entrantId,
                count($missingNames),
                implode(', ', $missingNames),
            ),
            __('Einchecken geht erst, wenn alle im Team am Einlass waren. Noch nicht eingelassen: :names.', [
                'names' => implode(', ', $missingNames),
            ]),
        );
    }
}
