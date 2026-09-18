<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Person;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\MediaItem;

/**
 * The ticket page (#292 §4.5): one admission on screen for its holder and
 * for the buyer who manages it, with the same scan code the PDF prints.
 */
it('shows the holder their ticket with the banner, the seat and the same code the PDF carries', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The hall']);
    ticketEventSummary($brand, $eventId, ['banner_media_id' => $banner->id]);
    $holder = User::factory()->create();
    Person::query()->updateOrCreate(['id' => $holder->id], ['display_name' => 'Erika Musterfrau', 'email' => $holder->email]);
    $admission = Admission::factory()->create([
        'event_id' => $eventId,
        'holder_user_id' => $holder->id,
        'state' => AdmissionState::Assigned,
        'role' => 'visitor',
    ]);
    $seat = new AdmissionSeat(['admission_id' => $admission->id, 'booking_id' => (string) Str::ulid(), 'place_ref' => 'A12', 'place_label' => 'A12']);
    $seat->forceFill(['brand_id' => $brand->id])->save();

    $token = app(IssueAdmissionToken::class)->current($admission);

    $this->actingAs($holder)
        ->get('/my/tickets/'.$admission->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admission/tickets/Show')
            ->where('id', $admission->id)
            ->where('eventName', 'Kon Fest 2026')
            ->where('eventSlug', 'kon-fest-2026')
            ->where('venueLabel', 'Halle 3, Musterstadt')
            ->where('bannerUrl', fn (?string $url): bool => is_string($url) && str_contains($url, $banner->path))
            ->where('bannerAlt', 'The hall')
            ->where('holderName', 'Erika Musterfrau')
            ->where('isHolder', true)
            ->where('isManager', false)
            ->where('checkedIn', false)
            ->where('seat.placeLabel', 'A12')
            ->where('canPickSeat', false)
            ->where('qrSvg', fn (string $svg): bool => str_starts_with($svg, '<svg') && ! str_contains($svg, '<?xml'))
            ->where('doorCode', fn (string $code): bool => preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code) === 1));

    // Opening the page did not rotate the code (`.ai/rules/tickets.md`).
    expect(app(IssueAdmissionToken::class)->current($admission->fresh()))->toBe($token);
});

it('shows the manager an unclaimed ticket of theirs, and refuses everybody else', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $manager = User::factory()->create();
    $stranger = User::factory()->create();
    $admission = Admission::factory()->forRegistration((string) Str::ulid())->create([
        'event_id' => $eventId,
        'holder_user_id' => null,
        'buyer_user_id' => $manager->id,
    ]);
    CheckIn::factory()->create(['admission_id' => $admission->id, 'registration_id' => $admission->registration_id]);

    $this->actingAs($manager)
        ->get('/my/tickets/'.$admission->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isManager', true)
            ->where('isHolder', false)
            ->where('holderName', 'Unassigned')
            ->where('checkedIn', true)
            ->where('seat', null)
            ->where('registrationId', $admission->registration_id));

    $this->actingAs($stranger)->get('/my/tickets/'.$admission->id)->assertForbidden();
    $this->get('/my/tickets/'.$admission->id)->assertRedirect('/login');
});

it('404s a revoked ticket even for its holder', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $holder = User::factory()->create();
    $admission = Admission::factory()->revoked()->create(['holder_user_id' => $holder->id]);

    $this->actingAs($holder)->get('/my/tickets/'.$admission->id)->assertNotFound();
});
