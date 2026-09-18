<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\AssignHolder;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Actions\UnassignHolder;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Exceptions\HolderChangeRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\CheckIn;
use Konventix\Identity\Models\User;

/**
 * `AssignHolder` and `UnassignHolder` (#292 §4.1, #16 point 3): the buyer of
 * a registration — its admissions' *manager* — names themselves or takes a
 * ticket back; an organizer does the same at the desk by capability.
 * Helper names prefixed (.ai/rules/tests.md).
 */
function holderTestPlaceholder(?string $buyerUserId = null): Admission
{
    return Admission::factory()->forRegistration((string) Str::ulid())->create([
        'holder_user_id' => null,
        'state' => AdmissionState::Issued,
        'buyer_user_id' => $buyerUserId ?? (string) Str::ulid(),
    ]);
}

it('lets the buyer name themselves the holder, and says so without a claim link', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $admission = holderTestPlaceholder($buyer->id);
    Event::fake([AdmissionHolderAssigned::class]);

    $result = app(AssignHolder::class)->handle($admission, $buyer->id, AssignHolder::ACTOR_MANAGER, $buyer->id);

    expect($result->holder_user_id)->toBe($buyer->id)
        ->and($result->state)->toBe(AdmissionState::Assigned);

    Event::assertDispatched(
        AdmissionHolderAssigned::class,
        fn (AdmissionHolderAssigned $e): bool => $e->admissionId === $admission->id
            && $e->holderUserId === $buyer->id
            && $e->viaClaimLink === false,
    );
});

it('revokes a live claim link when the holder is named directly', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $admission = holderTestPlaceholder($buyer->id);
    $link = app(IssueAdmissionClaimLink::class)->handle($admission, 'friend@example.test');

    app(AssignHolder::class)->handle($admission, $buyer->id, AssignHolder::ACTOR_MANAGER, $buyer->id);

    expect(AdmissionClaimLink::withoutBrandScope()->findOrFail($link->id)->revoked_at)->not->toBeNull();
});

it('refuses a manager who did not buy the registration', function (): void {
    $this->withBrand();
    $admission = holderTestPlaceholder();
    $stranger = User::factory()->create();

    expect(fn () => app(AssignHolder::class)->handle($admission, $stranger->id, AssignHolder::ACTOR_MANAGER, $stranger->id))
        ->toThrow(AuthorizationException::class);

    expect($admission->fresh()->holder_user_id)->toBeNull();
});

it('refuses to name a holder on an admission that has one, or that was revoked', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $held = Admission::factory()->forRegistration((string) Str::ulid())->assigned()->create(['buyer_user_id' => $buyer->id]);
    $revoked = Admission::factory()->forRegistration((string) Str::ulid())->revoked()->create(['buyer_user_id' => $buyer->id, 'holder_user_id' => null]);

    expect(fn () => app(AssignHolder::class)->handle($held, $buyer->id, AssignHolder::ACTOR_MANAGER, $buyer->id))
        ->toThrow(HolderChangeRefusedException::class, 'already has a holder');
    expect(fn () => app(AssignHolder::class)->handle($revoked, $buyer->id, AssignHolder::ACTOR_MANAGER, $buyer->id))
        ->toThrow(HolderChangeRefusedException::class, 'no longer valid');
});

it('lets an operator with admission.assign-holder name anybody, and refuses one without', function (): void {
    $brand = $this->withBrand();
    $admission = holderTestPlaceholder();
    $desk = admissionUserWithPermission($brand->id, 'admission.assign-holder');
    $someone = (string) Str::ulid();
    $noPermission = User::factory()->create();

    app(AssignHolder::class)->handle($admission, $someone, AssignHolder::ACTOR_OPERATOR, $desk->id);

    expect($admission->fresh()->holder_user_id)->toBe($someone);

    $another = holderTestPlaceholder();

    expect(fn () => app(AssignHolder::class)->handle($another, $someone, AssignHolder::ACTOR_OPERATOR, $noPermission->id))
        ->toThrow(AuthorizationException::class);
});

it('lets the buyer take a ticket back, keeping the row and announcing who lost it', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $holder = (string) Str::ulid();
    $admission = Admission::factory()->forRegistration((string) Str::ulid())->assigned($holder)->create(['buyer_user_id' => $buyer->id]);
    Event::fake([AdmissionHolderUnassigned::class]);

    $result = app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_MANAGER, $buyer->id, UnassignHolder::REASON_MANAGER);

    expect($result->holder_user_id)->toBeNull()
        ->and($result->state)->toBe(AdmissionState::Issued);

    Event::assertDispatched(
        AdmissionHolderUnassigned::class,
        fn (AdmissionHolderUnassigned $e): bool => $e->admissionId === $admission->id
            && $e->previousHolderUserId === $holder
            && $e->unassignedByUserId === $buyer->id
            && $e->reasonCode === 'manager_unassigned',
    );
});

it('refuses to take back a ticket the door has already accepted', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $admission = Admission::factory()->forRegistration((string) Str::ulid())->assigned()->create(['buyer_user_id' => $buyer->id]);
    CheckIn::factory()->create(['admission_id' => $admission->id, 'registration_id' => $admission->registration_id]);

    expect(fn () => app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_MANAGER, $buyer->id, UnassignHolder::REASON_MANAGER))
        ->toThrow(HolderChangeRefusedException::class, 'already been used');

    expect($admission->fresh()->holder_user_id)->not->toBeNull();
});

it('refuses a take-back by a stranger, and by an operator without admission.reassign-holder', function (): void {
    $brand = $this->withBrand();
    $admission = Admission::factory()->forRegistration((string) Str::ulid())->assigned()->create(['buyer_user_id' => (string) Str::ulid()]);
    $stranger = User::factory()->create();

    expect(fn () => app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_MANAGER, $stranger->id, UnassignHolder::REASON_MANAGER))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_OPERATOR, $stranger->id, UnassignHolder::REASON_DESK))
        ->toThrow(AuthorizationException::class);

    $desk = admissionUserWithPermission($brand->id, 'admission.reassign-holder');

    app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_OPERATOR, $desk->id, UnassignHolder::REASON_DESK);

    expect($admission->fresh()->holder_user_id)->toBeNull();
});

it('refuses to take back a ticket nobody holds', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $admission = holderTestPlaceholder($buyer->id);

    expect(fn () => app(UnassignHolder::class)->handle($admission, UnassignHolder::ACTOR_MANAGER, $buyer->id, UnassignHolder::REASON_MANAGER))
        ->toThrow(HolderChangeRefusedException::class, 'no holder');
});
