<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\CheckInEntrant;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\DisputeMatchResult;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;

/**
 * The two §12 notices built in Task 8's fix round (design §14, "The two §12
 * notices"): `competition.match-disputed` to the other side of a dispute and
 * `competition.checked-in` to a checked-in roster. Each is sent once, to the
 * right people, after the commit, and never for a brand that has the module
 * off.
 *
 * Messaging's `SendMessage` is replaced by a recorder, so the test asserts who
 * was addressed with which template and nothing about delivery, which is
 * Messaging's business. `NotifiesRosterMembers` resolves it per recipient,
 * which is what makes the swap possible.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */

/**
 * Records every send request and returns the list by reference.
 *
 * @return ArrayObject<int, SendMessageRequest>
 */
function noticesTestRecorder(): ArrayObject
{
    $sent = new ArrayObject;

    app()->instance(SendMessage::class, new class($sent)
    {
        /** @param  ArrayObject<int, SendMessageRequest>  $sent */
        public function __construct(private ArrayObject $sent) {}

        /** @return list<mixed> */
        public function handle(SendMessageRequest $request): array
        {
            $this->sent->append($request);

            return [];
        }
    });

    return $sent;
}

/**
 * The recipients of one template, in send order.
 *
 * @param  ArrayObject<int, SendMessageRequest>  $sent
 * @return list<string|null>
 */
function noticesTestRecipients(ArrayObject $sent, string $templateKey): array
{
    return array_values(array_map(
        fn (SendMessageRequest $request): ?string => $request->recipientId,
        array_filter($sent->getArrayCopy(), fn (SendMessageRequest $request): bool => $request->templateKey === $templateKey),
    ));
}

/**
 * A two-entrant match whose sides may report.
 *
 * @return array{0: Competition, 1: CompetitionMatch, 2: array<int, string>, 3: array<string, string>}
 */
function noticesTestMatch(): array
{
    [$competition, , $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);

    $stage = competitionGeneratedStage($competition);
    $match = competitionMatchAt($stage, 1, 1);

    return [$competition, $match, competitionSidesOf($match), $members];
}

function noticesTestEntrant(string $entrantId): Entrant
{
    /** @var Entrant */
    return Entrant::query()->findOrFail($entrantId);
}

it('tells the other side once when its result is disputed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    [, $match, $sides, $members] = noticesTestMatch();

    $sent = noticesTestRecorder();

    app(ReportMatchResult::class)->handle($match, noticesTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
    app(DisputeMatchResult::class)->handle($match->fresh(), noticesTestEntrant($sides[2]), 'Das war 9:16.', $members[$sides[2]]);

    // Side 1 reported and is contested; side 2 raised the dispute and is told
    // nothing about its own act.
    expect(noticesTestRecipients($sent, 'competition.match-disputed'))->toBe([$members[$sides[1]]]);
});

it('tells the first reporter once when a counter-report turns into a dispute', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    [, $match, $sides, $members] = noticesTestMatch();

    $sent = noticesTestRecorder();

    app(ReportMatchResult::class)->handle($match, noticesTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
    app(ReportMatchResult::class)->handle($match->fresh(), noticesTestEntrant($sides[2]), new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$sides[2]]);

    expect($match->fresh()->isDisputed())->toBeTrue()
        ->and(noticesTestRecipients($sent, 'competition.match-disputed'))->toBe([$members[$sides[1]]])
        // The first report told side 2 once; the counter-report tells nobody
        // that a result was reported, it is a dispute.
        ->and(noticesTestRecipients($sent, 'competition.result-reported'))->toBe([$members[$sides[2]]]);
});

it('tells the whole roster once when the desk checks a team in, and nobody on a repeat click', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = Competition::factory()->create([
        'event_id' => $eventId,
        'discipline_id' => Discipline::factory()->teamSize(2)->create()->getKey(),
        'check_in_mode' => CheckInMode::Desk,
    ]);

    $captain = competitionEligibleUser($eventId, (string) $brand->getKey());
    $mate = competitionEligibleUser($eventId, (string) $brand->getKey());
    $team = app(CreateTeam::class)->handle((string) $brand->getKey(), $eventId, 'Zwei', $captain);
    app(JoinTeamByCode::class)->handle((string) $brand->getKey(), $team->refresh()->invite_code, $mate);
    $entrant = app(EnterCompetition::class)->handle($competition, $team->refresh());

    $sent = noticesTestRecorder();

    app(CheckInEntrant::class)->handle($entrant);
    app(CheckInEntrant::class)->handle($entrant->refresh());

    expect(noticesTestRecipients($sent, 'competition.checked-in'))->toBe([$captain, $mate]);
});

it('leaves out the captain who checked the team in themselves', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    // The window is open: the competition starts in two hours and check-in
    // opens three hours before it (§6).
    $eventId = (string) Str::ulid();
    $competition = Competition::factory()->create([
        'event_id' => $eventId,
        'discipline_id' => Discipline::factory()->teamSize(2)->create()->getKey(),
        'check_in_mode' => CheckInMode::SelfService,
        'starts_at' => now()->addHours(2),
        'check_in_opens_minutes_before' => 180,
    ]);

    $captain = competitionEligibleUser($eventId, (string) $brand->getKey());
    $mate = competitionEligibleUser($eventId, (string) $brand->getKey());
    $team = app(CreateTeam::class)->handle((string) $brand->getKey(), $eventId, 'Zwei', $captain);
    app(JoinTeamByCode::class)->handle((string) $brand->getKey(), $team->refresh()->invite_code, $mate);
    $entrant = app(EnterCompetition::class)->handle($competition, $team->refresh());

    $sent = noticesTestRecorder();

    app(CheckInEntrant::class)->handle($entrant, $captain);

    expect(noticesTestRecipients($sent, 'competition.checked-in'))->toBe([$mate]);
});

it('sends neither notice for a brand that has the module off', function (): void {
    $this->withBrand();
    [$competition, $match, $sides, $members] = noticesTestMatch();

    $sent = noticesTestRecorder();

    app(ReportMatchResult::class)->handle($match, noticesTestEntrant($sides[1]), new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$sides[1]]);
    app(DisputeMatchResult::class)->handle($match->fresh(), noticesTestEntrant($sides[2]), 'Das war 9:16.', $members[$sides[2]]);

    // The field builder's entrants are registered, so the desk can check one in.
    app(CheckInEntrant::class)->handle(noticesTestEntrant($sides[1]));

    expect($match->fresh()->isDisputed())->toBeTrue()
        ->and(noticesTestEntrant($sides[1])->checked_in_at)->not->toBeNull()
        ->and(noticesTestRecipients($sent, 'competition.match-disputed'))->toBe([])
        ->and(noticesTestRecipients($sent, 'competition.checked-in'))->toBe([]);

    expect($competition->exists)->toBeTrue();
});
