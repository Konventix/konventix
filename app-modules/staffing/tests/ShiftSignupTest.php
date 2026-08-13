<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Staffing\Actions\ClaimShiftRole;
use Konventix\Staffing\Actions\ConfirmAssignment;
use Konventix\Staffing\Actions\ReleaseAssignment;
use Konventix\Staffing\Enums\AssignmentState;
use Konventix\Staffing\Events\AssignmentAccepted;
use Konventix\Staffing\Events\ShiftUnderstaffed;
use Konventix\Staffing\Exceptions\ShiftRoleUnavailableException;
use Konventix\Staffing\Models\Assignment;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\ShiftRole;
use Konventix\Staffing\Models\Team;

/**
 * Self-service sign-up (D-STF-01) on flat teams (D-STF-02), and the crew
 * badge an accepted assignment produces.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function staffingTestRole(bool $requiresApproval = false, int $headcount = 1): ShiftRole
{
    $team = Team::factory()->when($requiresApproval, fn ($f) => $f->requiringApproval())->create();
    $shift = Shift::factory()->forTeam($team)->create();

    return ShiftRole::factory()->forShift($shift)->needing($headcount)->create();
}

it('accepts a self-service claim outright on an open team', function (): void {
    // D-STF-01 settles self-service *with* approval as the opt-in, so making
    // every volunteer wait for a rubber stamp would invert the decision.
    $this->withBrand();

    $assignment = app(ClaimShiftRole::class)->handle(staffingTestRole(), (string) Str::ulid());

    expect($assignment->state)->toBe(AssignmentState::Accepted)
        ->and($assignment->responded_at)->not->toBeNull();
});

it('offers rather than accepts on a team that opted into approval', function (): void {
    $this->withBrand();

    $assignment = app(ClaimShiftRole::class)->handle(staffingTestRole(requiresApproval: true), (string) Str::ulid());

    expect($assignment->state)->toBe(AssignmentState::Offered)
        ->and($assignment->offered_at)->not->toBeNull();
});

it('counts an outstanding offer against headcount', function (): void {
    // A lead must not fill the same slot twice while waiting for an answer.
    $this->withBrand();

    $role = staffingTestRole(requiresApproval: true, headcount: 1);
    app(ClaimShiftRole::class)->handle($role, (string) Str::ulid());

    expect($role->fresh()->isFull())->toBeTrue();
});

it('refuses a claim once the role is full', function (): void {
    $this->withBrand();

    $role = staffingTestRole(headcount: 1);
    app(ClaimShiftRole::class)->handle($role, (string) Str::ulid());

    expect(fn () => app(ClaimShiftRole::class)->handle($role, (string) Str::ulid()))
        ->toThrow(ShiftRoleUnavailableException::class);
});

it('refuses a claim on an unpublished shift', function (): void {
    $this->withBrand();

    $team = Team::factory()->create();
    $shift = Shift::factory()->forTeam($team)->unpublished()->create();
    $role = ShiftRole::factory()->forShift($shift)->create();

    expect(fn () => app(ClaimShiftRole::class)->handle($role, (string) Str::ulid()))
        ->toThrow(ShiftRoleUnavailableException::class);
});

it('stops one person signing up twice at the database level', function (): void {
    // A double-tap on a phone at a venue is exactly the race a read-then-write
    // loses, and an overstated coverage count is worse than a rejected tap.
    $this->withBrand();

    $role = staffingTestRole(headcount: 5);
    $userId = (string) Str::ulid();

    app(ClaimShiftRole::class)->handle($role, $userId);

    expect(fn () => app(ClaimShiftRole::class)->handle($role, $userId))
        ->toThrow(ShiftRoleUnavailableException::class);

    expect(Assignment::query()->where('shift_role_id', $role->id)->count())->toBe(1);
});

it('lets the same person sign up again after releasing', function (): void {
    $this->withBrand();

    $role = staffingTestRole(headcount: 5);
    $userId = (string) Str::ulid();

    $first = app(ClaimShiftRole::class)->handle($role, $userId);
    app(ReleaseAssignment::class)->handle($first);

    expect(app(ClaimShiftRole::class)->handle($role, $userId)->state)
        ->toBe(AssignmentState::Accepted);
});

it('emits AssignmentAccepted once, whichever route the person took', function (): void {
    // Admission's crew-badge listener has exactly one event to react to.
    $this->withBrand();

    Event::fake([AssignmentAccepted::class]);

    app(ClaimShiftRole::class)->handle(staffingTestRole(), (string) Str::ulid());

    $offered = app(ClaimShiftRole::class)->handle(staffingTestRole(requiresApproval: true), (string) Str::ulid());
    app(ConfirmAssignment::class)->handle($offered);

    Event::assertDispatchedTimes(AssignmentAccepted::class, 2);
});

it('announces understaffing only when a release opens a gap', function (): void {
    $this->withBrand();

    $role = staffingTestRole(headcount: 2);
    $first = app(ClaimShiftRole::class)->handle($role, (string) Str::ulid());
    app(ClaimShiftRole::class)->handle($role, (string) Str::ulid());

    Event::fake([ShiftUnderstaffed::class]);

    app(ReleaseAssignment::class)->handle($first);

    Event::assertDispatched(
        ShiftUnderstaffed::class,
        fn (ShiftUnderstaffed $e): bool => $e->requiredHeadcount === 2 && $e->acceptedHeadcount === 1,
    );
});

it('is idempotent when releasing twice', function (): void {
    $this->withBrand();

    $assignment = app(ClaimShiftRole::class)->handle(staffingTestRole(), (string) Str::ulid());
    app(ReleaseAssignment::class)->handle($assignment);

    Event::fake([ShiftUnderstaffed::class]);
    app(ReleaseAssignment::class)->handle($assignment->fresh());

    Event::assertNotDispatched(ShiftUnderstaffed::class);
});

it('issues a crew admission with no registration behind it', function (): void {
    // D-STF-01's whole point: a rostered person gets a badge without a
    // commercial act. `registration_id` had to become nullable for this.
    $this->withBrand();

    $role = staffingTestRole();
    $userId = (string) Str::ulid();

    $assignment = app(ClaimShiftRole::class)->handle($role, $userId);

    $admission = Admission::query()->where('assignment_id', $assignment->id)->firstOrFail();

    expect($admission->registration_id)->toBeNull()
        ->and($admission->holder_user_id)->toBe($userId)
        ->and($admission->role)->toBe('crew')
        // Assigned from birth: a crew badge belongs to a named person the
        // moment it exists, so there is no placeholder phase.
        ->and($admission->state)->toBe(AdmissionState::Assigned);
});

it('issues one crew badge however often the acceptance is replayed', function (): void {
    $this->withBrand();

    $offered = app(ClaimShiftRole::class)->handle(staffingTestRole(requiresApproval: true), (string) Str::ulid());

    app(ConfirmAssignment::class)->handle($offered);
    app(ConfirmAssignment::class)->handle($offered->fresh());

    expect(Admission::query()->where('assignment_id', $offered->id)->count())->toBe(1);
});

it('carries the shift role admission role onto the badge', function (): void {
    // Staffing knows what someone is rostered as; Admission only knows what
    // to print.
    $this->withBrand();

    $role = staffingTestRole();
    $role->forceFill(['admission_role' => 'exhibitor_crew'])->save();

    $assignment = app(ClaimShiftRole::class)->handle($role, (string) Str::ulid());

    expect(Admission::query()->where('assignment_id', $assignment->id)->firstOrFail()->role)
        ->toBe('exhibitor_crew');
});
