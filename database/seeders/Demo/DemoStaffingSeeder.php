<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Staffing\Actions\ClaimShiftRole;
use Konventix\Staffing\Actions\ConfirmAssignment;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\ShiftRole;
use Konventix\Staffing\Models\Team;

/**
 * The roster: two teams per event, shifts across the event's days, and
 * people signed up for them.
 *
 * The two teams differ on the one axis that matters (D-STF-01): the entrance
 * team is self-service, so a claim is accepted immediately and emits
 * `AssignmentAccepted` — which is what makes Admission issue a crew badge
 * without any registration behind it. The second team requires approval, so
 * its claims sit as offers until a lead confirms them, and one is left
 * outstanding so the approval queue is not empty. Which team that is comes
 * from the archetype: a LAN staffs a bar, a fair staffs an info desk.
 *
 * Everything goes through `ClaimShiftRole`, headcount guard included: an
 * over-subscribed role refuses, exactly as it does on the sign-up page.
 *
 * Idempotent per event.
 */
final class DemoStaffingSeeder
{
    public function __construct(
        private readonly ClaimShiftRole $claim,
        private readonly ConfirmAssignment $confirmAssignment,
    ) {}

    /**
     * @param  Collection<int, User>  $members
     */
    public function handle(Event $event, Collection $members, EventArchetype $archetype): void
    {
        if (Team::query()->where('event_id', $event->getKey())->exists()) {
            return;
        }

        $plan = $archetype->staffingPlan();

        $entrance = Team::factory()->forEvent($event->getKey())->create([
            'name' => 'Entrance',
            'area' => 'front-of-house',
        ]);

        $support = Team::factory()->forEvent($event->getKey())->requiringApproval()->create([
            'name' => $plan['supportTeam'],
            'area' => 'front-of-house',
        ]);

        $selfService = $this->seedShift($entrance, $event, $plan['doorsShift'], dayOffset: 0, roles: [
            ['name' => 'Scanner', 'admission_role' => 'crew', 'required_headcount' => 3],
            ['name' => 'Queue lead', 'admission_role' => 'crew', 'required_headcount' => 1],
        ]);

        $approved = $this->seedShift($support, $event, $plan['supportShift'], dayOffset: 1, roles: [
            ['name' => $plan['supportRole'], 'admission_role' => 'crew', 'required_headcount' => 2],
        ]);

        // A shift nobody can sign up for yet: drafting a plan before
        // publishing it is the normal way a lead works.
        $this->seedShift($support, $event, 'Teardown', dayOffset: 2, published: false, roles: [
            ['name' => 'Runner', 'admission_role' => 'crew', 'required_headcount' => 4],
        ]);

        $this->staff($selfService, $members, confirmOffers: false);
        $this->staff($approved, $members->skip(4)->values(), confirmOffers: true);
    }

    /**
     * @param  list<array{name: string, admission_role: string, required_headcount: int}>  $roles
     * @return Collection<int, ShiftRole>
     */
    private function seedShift(
        Team $team,
        Event $event,
        string $title,
        int $dayOffset,
        array $roles,
        bool $published = true,
    ): Collection {
        $startsAt = $event->starts_at->copy()->addDays($dayOffset)->setTime(10, 0);

        $factory = Shift::factory()->forTeam($team);

        if (! $published) {
            $factory = $factory->unpublished();
        }

        $shift = $factory->create([
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(6),
        ]);

        return collect($roles)->map(
            fn (array $role): ShiftRole => ShiftRole::factory()->forShift($shift)->create($role),
        );
    }

    /**
     * @param  Collection<int, ShiftRole>  $roles
     * @param  Collection<int, User>  $members
     */
    private function staff(Collection $roles, Collection $members, bool $confirmOffers): void
    {
        $candidates = $members->values();
        $offset = 0;

        foreach ($roles as $role) {
            // One short of the requirement, so the coverage view has an
            // under-staffed role to highlight rather than a wall of green.
            $wanted = max(1, $role->required_headcount - 1);

            for ($filled = 0; $filled < $wanted; $filled++) {
                /** @var User|null $candidate */
                $candidate = $candidates->get($offset++);

                if ($candidate === null) {
                    return;
                }

                $assignment = $this->claim->handle($role, $candidate->getKey());

                // The approval-gated team: most offers get confirmed, the
                // last one is left for the lead to decide on.
                if ($confirmOffers && $assignment->state === AssignmentState::Offered && $filled === 0) {
                    $this->confirmAssignment->handle($assignment);
                }
            }
        }
    }
}
