<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceBooking;

/**
 * The picker per ticket (#292 §6.4): the same page for one admission,
 * opened to its holder and to the buyer who manages the group, a 404 for
 * everybody else — including a stranger who guesses the id.
 *
 * @return array{manager: User, holder: User, admission: ResourceAdmission, registrationId: string, eventId: string, places: list<BookableResource>}
 */
function admissionSeatPageScene(Brand $brand, SeatingMode $seatingMode = SeatingMode::Pick): array
{
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->getKey());
    $type = seatingCategory($eventId);
    ['places' => $places] = seatingPublishedPlan($eventId, $type, 2);
    $manager = User::factory()->create();
    $holder = User::factory()->create();
    $registrationId = (string) Str::ulid();
    seatingClaim($eventId, $registrationId, (string) $manager->getKey(), [$type->getKey()], 2, seatingMode: $seatingMode);
    $admission = seatingAdmission($registrationId, $eventId, (string) $holder->getKey(), (string) $manager->getKey());

    return [
        'manager' => $manager,
        'holder' => $holder,
        'admission' => $admission,
        'registrationId' => $registrationId,
        'eventId' => $eventId,
        'places' => $places,
    ];
}

it('opens the per-ticket picker for the holder and for the manager, capped at one place', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = admissionSeatPageScene($brand);

    foreach ([$scene['holder'], $scene['manager']] as $viewer) {
        $this->actingAs($viewer)
            ->get('/my/admissions/'.$scene['admission']->id.'/seat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('seating/Pick')
                ->where('admissionId', $scene['admission']->id)
                ->where('maxPlaces', 1)
                ->where('registrationId', $scene['registrationId'])
                ->where('claim.eventName', 'Seating test event')
                ->where('currentPlaceIds', []));
    }
});

it('saves one place for the ticket, answers availability, and gives the seat up again', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = admissionSeatPageScene($brand);
    [$a1] = $scene['places'];

    $this->actingAs($scene['holder'])
        ->post('/my/admissions/'.$scene['admission']->id.'/seat', ['placeIds' => [$a1->getKey()]])
        ->assertRedirect('/my/admissions/'.$scene['admission']->id.'/seat');

    $booking = ResourceBooking::query()->where('admission_id', $scene['admission']->id)->firstOrFail();
    expect($booking->resource_id)->toBe($a1->getKey())
        ->and($booking->state)->toBe(BookingState::Confirmed);

    $this->actingAs($scene['manager'])
        ->get('/my/admissions/'.$scene['admission']->id.'/seat/availability')
        ->assertOk()
        ->assertJsonPath('places.'.$a1->getKey(), 'mine');

    $this->actingAs($scene['manager'])
        ->delete('/my/admissions/'.$scene['admission']->id.'/seat')
        ->assertRedirect('/my/tickets/'.$scene['admission']->id);

    expect($booking->fresh()->state)->toBe(BookingState::Released);
});

/**
 * The same swap guard as `SeatPickPageTest` for the per-ticket path
 * (2026-09-21 bugfix round): a second post with another place moves the
 * ticket, releases its old booking as `repicked`, and leaves exactly one
 * occupying booking bound to the admission.
 */
it('swaps the ticket\'s place on a second post, releasing the old booking as repicked', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = admissionSeatPageScene($brand);
    [$a1, $a2] = $scene['places'];

    $this->actingAs($scene['holder'])
        ->post('/my/admissions/'.$scene['admission']->id.'/seat', ['placeIds' => [$a1->getKey()]])
        ->assertRedirect('/my/admissions/'.$scene['admission']->id.'/seat');

    $old = ResourceBooking::query()->where('admission_id', $scene['admission']->id)->sole();

    Event::fake([ResourceBookingReleased::class]);

    $this->actingAs($scene['holder'])
        ->from('/my/admissions/'.$scene['admission']->id.'/seat')
        ->post('/my/admissions/'.$scene['admission']->id.'/seat', ['placeIds' => [$a2->getKey()]])
        ->assertRedirect('/my/admissions/'.$scene['admission']->id.'/seat')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Your seat is saved.');

    // #317: the picker the save lands back on shows the change — the new
    // place is the ticket's own, the old one free again.
    $this->actingAs($scene['holder'])
        ->get('/my/admissions/'.$scene['admission']->id.'/seat')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('seating/Pick')
            ->where('currentPlaceIds', [$a2->getKey()])
            ->where('availability.places.'.$a2->getKey(), 'mine')
            ->where('availability.places.'.$a1->getKey(), 'free'));

    $new = ResourceBooking::query()
        ->where('admission_id', $scene['admission']->id)
        ->whereIn('state', BookingState::occupyingValues())
        ->sole();

    expect($new->resource_id)->toBe($a2->getKey())
        ->and($new->state)->toBe(BookingState::Confirmed)
        ->and($old->fresh()->state)->toBe(BookingState::Released)
        ->and(ResourceBooking::query()
            ->where('registration_id', $scene['registrationId'])
            ->whereIn('state', BookingState::occupyingValues())
            ->count())->toBe(1);

    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $event): bool => $event->bookingId === $old->getKey() && $event->reason === 'repicked',
    );
});

it('404s a stranger, a revoked admission and one without a claim', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = admissionSeatPageScene($brand);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('/my/admissions/'.$scene['admission']->id.'/seat')->assertNotFound();
    $this->actingAs($stranger)->post('/my/admissions/'.$scene['admission']->id.'/seat', ['placeIds' => [$scene['places'][0]->getKey()]])->assertNotFound();

    $scene['admission']->forceFill(['state' => ResourceAdmission::STATE_REVOKED])->save();
    $this->actingAs($scene['holder'])->get('/my/admissions/'.$scene['admission']->id.'/seat')->assertNotFound();

    $orphan = seatingAdmission((string) Str::ulid(), $scene['eventId'], (string) $scene['holder']->getKey());
    $this->actingAs($scene['holder'])->get('/my/admissions/'.$orphan->id.'/seat')->assertNotFound();
});

it('refuses a post against an assign-mode claim as a form error', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = admissionSeatPageScene($brand, SeatingMode::Assign);

    $this->actingAs($scene['holder'])
        ->from('/my/admissions/'.$scene['admission']->id.'/seat')
        ->post('/my/admissions/'.$scene['admission']->id.'/seat', ['placeIds' => [$scene['places'][0]->getKey()]])
        ->assertRedirect('/my/admissions/'.$scene['admission']->id.'/seat')
        ->assertSessionHasErrors('placeIds');
});
