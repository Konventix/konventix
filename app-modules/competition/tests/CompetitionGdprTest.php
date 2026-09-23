<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;

/**
 * Design §13: `CompetitionGdprHandler` exports what a person did here —
 * reports, score entries and history rows, beside the memberships,
 * interests and linked accounts earlier slices already export — and
 * erasure reaches every table added since, the eligibility read model
 * included.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */

/**
 * One person who has reported a result for their side, submitted a
 * leaderboard entry and holds a confirmed registration.
 *
 * @return array{subject: string, matchId: string, entrantId: string, stageId: string, eventId: string}
 */
function gdprTestPerson(): array
{
    [$competition, , $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);

    $stage = competitionGeneratedStage($competition);
    $match = competitionMatchAt($stage, 1, 1);
    $sides = competitionSidesOf($match);
    $subject = $members[$sides[1]];

    /** @var Entrant $entrant */
    $entrant = Entrant::query()->findOrFail($sides[1]);

    app(ReportMatchResult::class)->handle($match, $entrant, new MatchReportData(scores: [1 => 16, 2 => 9]), $subject);

    $board = Competition::factory()->create(['result_submission_mode' => ResultSubmissionMode::Participants]);
    $boardStage = Stage::factory()->leaderboard(new LeaderboardSettings)->create(['competition_id' => $board->getKey()]);
    $boardEntrant = Entrant::factory()->create(['competition_id' => $board->getKey()]);

    (new ScoreEntry([
        'stage_id' => $boardStage->getKey(),
        'entrant_id' => $boardEntrant->getKey(),
        'value' => 41.9,
        'note' => 'Bestzeit auf der Wüstenstrecke',
        'submitted_by_user_id' => $subject,
    ]))->save();

    competitionEligibleUser($competition->event_id, (string) $competition->brand_id, $subject);

    return [
        'subject' => $subject,
        'matchId' => (string) $match->getKey(),
        'entrantId' => $sides[1],
        'stageId' => (string) $boardStage->getKey(),
        'eventId' => (string) $competition->event_id,
    ];
}

it('exports the reports, score entries and history rows a person wrote', function (): void {
    $this->withBrand();

    $person = gdprTestPerson();

    $export = (new CompetitionGdprHandler)->exportFor(SubjectRef::forUser($person['subject']));

    expect($export)->toHaveKeys(['team_memberships', 'interests', 'linked_accounts', 'result_reports', 'score_entries', 'match_history'])
        // One captaincy, from the field builder.
        ->and($export['team_memberships'])->toHaveCount(1)
        ->and($export['result_reports'])->toHaveCount(1)
        ->and($export['result_reports'][0])->toMatchArray([
            'competition_match_id' => $person['matchId'],
            'entrant_id' => $person['entrantId'],
            'scores' => [1 => 16, 2 => 9],
            'proof_media_id' => null,
        ])
        ->and($export['score_entries'])->toHaveCount(1)
        ->and($export['score_entries'][0])->toMatchArray([
            'stage_id' => $person['stageId'],
            'value' => 41.9,
            'note' => 'Bestzeit auf der Wüstenstrecke',
            'submitted' => true,
            'verified' => false,
            'rejected' => false,
            'rejection_note' => null,
        ])
        // The report wrote one history row with the person as its actor
        // (§8.6); nothing else in this story names them.
        ->and(array_column($export['match_history'], 'kind'))->toBe(['reported'])
        ->and($export['match_history'][0]['competition_match_id'])->toBe($person['matchId']);
});

it('exports nothing for somebody who never took part', function (): void {
    $this->withBrand();

    gdprTestPerson();

    $export = (new CompetitionGdprHandler)->exportFor(SubjectRef::forUser((string) Str::ulid()));

    expect($export['result_reports'])->toBe([])
        ->and($export['score_entries'])->toBe([])
        ->and($export['match_history'])->toBe([]);
});

it('forgets the person on every row that stays, eligibility included', function (): void {
    $this->withBrand();

    $person = gdprTestPerson();
    $subject = SubjectRef::forUser($person['subject']);

    expect(EligibleRegistration::query()->where('user_id', $person['subject'])->count())->toBe(1);

    (new CompetitionGdprHandler)->delete($subject, DeletionMode::Erase);

    // The rows are the competition's history and stay; the person does not.
    expect(EligibleRegistration::query()->where('event_id', $person['eventId'])->count())->toBe(1)
        ->and(EligibleRegistration::query()->where('user_id', $person['subject'])->count())->toBe(0)
        ->and(MatchResultReport::query()->where('competition_match_id', $person['matchId'])->sole()->reported_by_user_id)->toBeNull()
        ->and(MatchHistoryEntry::query()->where('competition_match_id', $person['matchId'])->where('actor_user_id', $person['subject'])->exists())->toBeFalse()
        ->and(ScoreEntry::query()->where('stage_id', $person['stageId'])->sole()->submitted_by_user_id)->toBeNull();

    // And a second export finds nothing left to show.
    $export = (new CompetitionGdprHandler)->exportFor($subject);

    expect($export['result_reports'])->toBe([])
        ->and($export['score_entries'])->toBe([])
        ->and($export['match_history'])->toBe([]);
});

it('hands an erased captain\'s team to its longest-standing member, leaves a team of one captainless, and the pages still render', function (): void {
    // Controller ruling on final review part 4, I3 (design §14): erasure keeps
    // the membership row but not the captaincy, which would otherwise sit on
    // a forgotten account and lock the team out of check-in, withdrawal and
    // a new invite code for the rest of the event.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-27',
        name: 'SXLan 27',
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));
    event(new EventPublished(eventId: $eventId, brandId: (string) $brand->getKey(), publishedAt: '2026-10-01T10:00:00+00:00'));

    [$competition, [$full, $alone], $members] = competitionFieldWithRosters(2, ['event_id' => $eventId, 'slug' => 'main-cup']);
    $stage = competitionGeneratedStage($competition);
    $competition->forceFill(['status' => CompetitionStatus::Running])->save();

    $captain = $members[$full->id];
    $loneCaptain = $members[$alone->id];

    // Two more on the full team, joining a minute apart: the first of them
    // has stood longest after the captain.
    $joined = [];

    foreach (['first', 'second'] as $which) {
        $this->travel(1)->minutes();

        $joined[$which] = (string) Str::ulid();
        $member = new CompetitionTeamMember([
            'competition_team_id' => $full->competition_team_id,
            'user_id' => $joined[$which],
            'role' => TeamRole::Member,
        ]);
        $member->forceFill(['brand_id' => $full->brand_id])->save();
    }

    $handler = new CompetitionGdprHandler;
    $handler->delete(SubjectRef::forUser($captain), DeletionMode::Erase);
    $handler->delete(SubjectRef::forUser($loneCaptain), DeletionMode::Erase);

    /** @var CompetitionTeam $fullTeam */
    $fullTeam = CompetitionTeam::query()->findOrFail($full->competition_team_id);
    /** @var CompetitionTeam $loneTeam */
    $loneTeam = CompetitionTeam::query()->findOrFail($alone->competition_team_id);

    // The full team: the captain's row stays, as a member; the member who
    // joined first captains now; the later one is still a member.
    expect($fullTeam->captain()?->user_id)->toBe($joined['first'])
        ->and($fullTeam->isCaptain($captain))->toBeFalse()
        ->and(CompetitionTeamMember::query()->where('competition_team_id', $fullTeam->id)->where('user_id', $captain)->value('role'))->toBe(TeamRole::Member)
        ->and(CompetitionTeamMember::query()->where('competition_team_id', $fullTeam->id)->count())->toBe(3)
        // The lone captain's team has nobody to hand to: no captain, the desk
        // steps in. The row stays.
        ->and($loneTeam->captain())->toBeNull()
        ->and(CompetitionTeamMember::query()->where('competition_team_id', $loneTeam->id)->count())->toBe(1);

    // And the public pages of the team and its match still render.
    $match = competitionMatchAt($stage, 1, 1);

    $this->get('/events/sxlan-27/tournaments/main-cup/teams/'.$full->competition_team_id)->assertOk();
    $this->app->forgetScopedInstances();
    $this->get('/events/sxlan-27/tournaments/main-cup/matches/'.$match->id)->assertOk();
});
