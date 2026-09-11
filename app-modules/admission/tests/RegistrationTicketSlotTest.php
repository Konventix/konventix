<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;
use Konventix\Registration\Models\Registration;

/**
 * Admission's contribution to the registrant's own page (design §7),
 * mirroring Commerce's `RegistrationSettlementSlotTest.php`: the route is
 * `registration.mine.show`, owned by Registration, which never reads this
 * module's tables (ADR-0004) — only the slot's props do.
 */
function ticketSlotProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'admission/TicketLinkSlot');

    return $slot === null ? null : $slot['props']['ticket'];
}

it('names how many tickets the buyer\'s file holds', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Admission::factory()->count(2)->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);
    // A revoked one does not count.
    Admission::factory()->revoked()->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page) use ($registration): void {
            $ticket = ticketSlotProps($page);

            expect($ticket['registrationId'])->toBe($registration->getKey())
                ->and($ticket['ticketCount'])->toBe(2);
        });
});

it('is absent when the registration granted no admissions', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(ticketSlotProps($page))->toBeNull());
});

it('is absent once every admission it granted has been revoked', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Admission::factory()->revoked()->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(ticketSlotProps($page))->toBeNull());
});
