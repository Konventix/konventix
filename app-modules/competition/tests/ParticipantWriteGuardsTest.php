<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Models\User;

/**
 * What every participant write asks before it acts, beyond the Action's own
 * guards (final review fix wave B).
 *
 * Item 3: a participant write is keyed by a competition's or an entrant's id
 * and never passes through `/events/{slug}`, so it asks the tournament pages'
 * rule itself — the competition is not a draft and its event is public.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */
function writeGuardsTestMember(Entrant $entrant, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();

    $member = new CompetitionTeamMember([
        'competition_team_id' => $entrant->competition_team_id,
        'user_id' => (string) $user->getKey(),
        'role' => $role,
    ]);
    $member->forceFill(['brand_id' => $entrant->brand_id])->save();

    return $user;
}

it('answers every participant write by id with a 404 while the event is a draft, and lets each through once it is published', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    // Created and never published: a draft, whose tournament pages 404.
    $eventId = (string) Str::ulid();
    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-draft',
        name: 'SXLan Draft',
        startsAt: '2027-11-20 10:00:00',
        endsAt: '2027-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    // A published-and-closed competition (`SignupClosed`, visible) with a
    // drawn bracket whose sides may report, and which takes proofs.
    [$competition, [$a]] = competitionFieldWithRosters(2, [
        'event_id' => $eventId,
        'result_submission_mode' => ResultSubmissionMode::Participants,
        'proof_policy' => ProofPolicy::Optional,
    ]);
    $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);

    // Three people on $a's team, so no one account spends more than seven of
    // the ten writes a minute the participant budget allows.
    $reporter = writeGuardsTestMember($a);
    $member = writeGuardsTestMember($a);
    $captain = writeGuardsTestMember($a, TeamRole::Captain);

    $base = '/tournaments/'.$competition->id;
    $entry = '/my/tournaments/entries/'.$a->id;

    $this->actingAs($reporter)->post($base.'/matches/'.$match->id.'/report', ['scores' => [1 => 16, 2 => 9]])->assertNotFound();
    $this->actingAs($reporter)->post($base.'/interest', ['note' => null])->assertNotFound();
    $this->actingAs($captain)->post($base.'/entries', ['teamId' => $a->competition_team_id])->assertNotFound();
    $this->actingAs($captain)->post($base.'/score-entries', ['value' => null])->assertNotFound();
    $this->actingAs($captain)->withHeader('Accept', 'application/json')->post($base.'/proofs', ['entrant_id' => $a->id])->assertNotFound();
    $this->actingAs($member)->delete($entry)->assertNotFound();
    $this->actingAs($member)->post($entry.'/check-in')->assertNotFound();

    expect(MatchResultReport::query()->where('competition_match_id', $match->id)->exists())->toBeFalse()
        ->and(CompetitionInterest::query()->where('competition_id', $competition->id)->exists())->toBeFalse();

    event(new EventPublished(eventId: $eventId, brandId: (string) $brand->getKey(), publishedAt: '2026-10-01T10:00:00+00:00'));

    // The same writes now reach their controllers and Actions: each answers
    // with its own refusal, or succeeds, but none is a 404 any more.
    $this->actingAs($reporter)->post($base.'/matches/'.$match->id.'/report', ['scores' => [1 => 16, 2 => 9]])->assertSessionHasNoErrors();
    // No confirmed registration: §5.1's refusal, on the note.
    $this->actingAs($reporter)->post($base.'/interest', ['note' => null])->assertSessionHasErrors('note');
    // The bare-ULID captain `competitionFieldWithRosters()` made holds no
    // registration: the roster check's refusal, on the team.
    $this->actingAs($captain)->post($base.'/entries', ['teamId' => $a->competition_team_id])->assertSessionHasErrors('teamId');
    // A value is required: the form's own validation.
    $this->actingAs($captain)->post($base.'/score-entries', ['value' => null])->assertSessionHasErrors('value');
    // No file: validation again, a 422 rather than a 404.
    $this->actingAs($captain)->withHeader('Accept', 'application/json')->post($base.'/proofs', ['entrant_id' => $a->id])->assertUnprocessable();
    // A member, not the captain: §5.5's 403.
    $this->actingAs($member)->delete($entry)->assertForbidden();
    // No self-service window: the Action's refusal, on the entry.
    $this->actingAs($member)->post($entry.'/check-in')->assertSessionHasErrors('entrant');

    expect(MatchResultReport::query()->where('competition_match_id', $match->id)->count())->toBe(1);
});
