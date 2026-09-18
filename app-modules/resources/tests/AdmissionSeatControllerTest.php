<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\SeatingMode;
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
        ->assertRedirect('/my/tickets/'.$scene['admission']->id);

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
