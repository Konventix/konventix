<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Identity\Models\User;
use Konventix\Registration\Models\Registration;

/**
 * Admission's contribution to the registrant's own page — the ticket
 * manager (#292 §4.5, #16) — mirroring Commerce's
 * `RegistrationSettlementSlotTest.php`: the route is
 * `registration.mine.show`, owned by Registration, which never reads this
 * module's tables (ADR-0004) — only the slot's props do.
 */
function ticketSlotProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'admission/TicketManagerSlot');

    return $slot === null ? null : $slot['props']['manager'];
}

it('lists every live admission with its holder, invitation and check-in state', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $friend = ticketPerson(['display_name' => 'Grace Hopper']);

    $mine = Admission::factory()->create([
        'registration_id' => $registration->getKey(),
        'event_id' => $eventId,
        'buyer_user_id' => $user->getKey(),
        'holder_user_id' => $user->getKey(),
        'created_at' => now()->subMinutes(3),
    ]);
    $friends = Admission::factory()->create([
        'registration_id' => $registration->getKey(),
        'event_id' => $eventId,
        'buyer_user_id' => $user->getKey(),
        'holder_user_id' => $friend->id,
        'created_at' => now()->subMinutes(2),
    ]);
    CheckIn::factory()->create(['admission_id' => $friends->id, 'registration_id' => $registration->getKey()]);
    $open = Admission::factory()->create([
        'registration_id' => $registration->getKey(),
        'event_id' => $eventId,
        'buyer_user_id' => $user->getKey(),
        'holder_user_id' => null,
        'created_at' => now()->subMinute(),
    ]);
    $link = app(IssueAdmissionClaimLink::class)->handle($open, 'friend@example.test');
    // A revoked one does not appear.
    Admission::factory()->revoked()->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page) use ($registration, $mine, $friends, $open, $link): void {
            $manager = ticketSlotProps($page);

            expect($manager['registrationId'])->toBe($registration->getKey())
                ->and($manager['ticketCount'])->toBe(3)
                ->and($manager['holdsOne'])->toBeTrue()
                ->and(array_column($manager['admissions'], 'id'))->toBe([$mine->id, $friends->id, $open->id]);

            [$self, $held, $offered] = $manager['admissions'];

            expect($self['isSelf'])->toBeTrue()
                ->and($self['invite'])->toBeNull()
                ->and($self['checkedIn'])->toBeFalse()
                ->and($held['holderName'])->toBe('Grace Hopper')
                ->and($held['isSelf'])->toBeFalse()
                ->and($held['checkedIn'])->toBeTrue()
                ->and($offered['holderUserId'])->toBeNull()
                ->and($offered['invite']['intendedEmail'])->toBe('friend@example.test')
                ->and($offered['invite']['url'])->toContain($link->id)->toContain('signature=')
                ->and($offered['seat'])->toBeNull();
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
