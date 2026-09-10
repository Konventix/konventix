<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Registration\Models\Registration;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;

/**
 * Resources' contribution to the registrant's own page (seating design
 * §7.4): the chosen place refs, or the way to the picker while the claim
 * is unplaced. The direction matters as with Commerce's settlement slot —
 * Registration has already decided the row is the reader's; Resources
 * answers for one registration id and reads no Registration table.
 *
 * Row builders (`seatingClaim`, `seatingPublishedPlan`, …) are Resources'
 * tests/Helpers.php, loaded for every worker by tests/Pest.php.
 */
function seatSlotProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'resources/SeatSummarySlot');

    return $slot === null ? null : $slot['props']['summary'];
}

it('offers the picker while the claim is unplaced', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $user->getKey()]);
    $type = seatingCategory($registration->event_id);
    seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()], quantity: 2);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($registration): void {
            $summary = seatSlotProps($page);

            expect($summary)->not->toBeNull()
                ->and($summary['registrationId'])->toBe($registration->getKey())
                ->and($summary['claimState'])->toBe('unplaced')
                ->and($summary['quantity'])->toBe(2)
                ->and($summary['placeRefs'])->toBe([]);
        });
});

it('lists the chosen place refs once placed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $user->getKey()]);
    $window = seatingWindow($registration->event_id, $brand->getKey());
    $type = seatingCategory($registration->event_id);
    ['places' => $places] = seatingPublishedPlan($registration->event_id, $type, 3);
    $claim = seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()], quantity: 2, state: ClaimState::Placed);
    seatingBooking($places[2], $window, $registration->getKey(), $claim);
    seatingBooking($places[0], $window, $registration->getKey(), $claim);
    // Somebody else's place on the same plan never leaks into the summary.
    seatingBooking($places[1], $window, (string) Str::ulid());

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page): void {
            $summary = seatSlotProps($page);

            expect($summary['claimState'])->toBe('placed')
                ->and($summary['placeRefs'])->toBe(['A-01', 'A-03']);
        });
});

it('contributes nothing for a registration without a claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(seatSlotProps($page))->toBeNull());
});

/**
 * Item 1 of the 2026-09-10 fix wave (#278): an `assign`-mode claim is the
 * organiser's to place, so this slot must offer no link into the picker —
 * only the same explanatory text `Pick.vue` shows for the same reason.
 *
 * `seatingMode` is the one prop `SeatSummarySlot.vue` branches on to hide
 * both "Change your seat" and "Choose your seat" and show
 * `ASSIGNED_BY_ORGANISER_MESSAGE` instead (no SSR process runs in this
 * environment, so the rendered markup itself is not assertable here —
 * `npm run build` and `npm run types:check` cover the template compiling
 * against this prop shape).
 */
it('sends an assign-mode claim so the slot shows the explanation, not a picker link', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $user->getKey()]);
    $type = seatingCategory($registration->event_id);
    seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()], seatingMode: SeatingMode::Assign);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $summary = seatSlotProps($page);

            expect($summary['seatingMode'])->toBe('assign');
        });
});

it('is absent from the page while the module is off', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);
    $type = seatingCategory($registration->event_id);
    seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(
            collect($page->toArray()['props']['slots'])->pluck('component')->all(),
        )->not->toContain('resources/SeatSummarySlot'));
});

/**
 * The batch's own addition (2026-09-10, ahead of design §12b/#282's quota
 * mechanism): `HasFreePlaceForClaim` backs this slot's `noFreePlaces`.
 *
 * Corrected 2026-09-10 (fix-wave item 2, #278): this is not a read the
 * picker shares any more. `SeatPickerData` dropped its own `noFreePlaces`
 * field during an earlier review fix, and `Pick.vue` derives its "nothing
 * free" banner live from polled availability instead
 * (`categoriesHaveFreePlace()` in `eligibility.test.ts`, not a Pest test
 * here). The two surfaces answer over different row sets — this Action
 * counts every bookable resource of the claim's categories including a
 * plan-less one, the picker's derivation only ever sees resources placed
 * on a published plan of the claim's event — so they can, in principle,
 * disagree; see `HasFreePlaceForClaim`'s docblock.
 */
it('flags the slot the same way when nothing is free in the claim\'s categories', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $user->getKey()]);
    $window = seatingWindow($registration->event_id, $brand->getKey());
    $type = seatingCategory($registration->event_id);
    ['places' => $places] = seatingPublishedPlan($registration->event_id, $type, 1);
    seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()], quantity: 1);
    seatingBooking($places[0], $window, (string) Str::ulid());

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page): void {
            $summary = seatSlotProps($page);

            expect($summary['noFreePlaces'])->toBeTrue();
        });
});

it('does not flag the slot while a place in the claim\'s categories is still free', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();
    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $user->getKey()]);
    $type = seatingCategory($registration->event_id);
    seatingPublishedPlan($registration->event_id, $type, 1);
    seatingClaim($registration->event_id, $registration->getKey(), (string) $user->getKey(), [$type->getKey()], quantity: 1);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page): void {
            $summary = seatSlotProps($page);

            expect($summary['noFreePlaces'])->toBeFalse();
        });
});
