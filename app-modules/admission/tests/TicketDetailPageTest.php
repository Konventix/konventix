<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\Person;
use Konventix\Admission\Support\TicketReference;

/**
 * The ticket detail page (#329, attendee-list design §4.4, §7, D-ATT-05):
 * the history in `scanned_at` order with door and console rows told apart,
 * the door checks with their answers, the ids the page links to, the badge
 * link only for `admission.print-badge`, and the actions by the list's rule.
 *
 * Every test runs at 2026-10-16 18:00 UTC on `attendeeFixture()`'s one event
 * day. Brand-owned rows first, then admin-host requests (.ai/rules/tests.md).
 * Local helpers are prefixed `ticketDetail*`.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
});

function ticketDetailInstant(string $at): callable
{
    return fn (string $value): bool => CarbonImmutable::parse($value)->equalTo(CarbonImmutable::parse($at, 'UTC'));
}

it('shows the history in scanned_at order, door and console apart, and the door checks with their answers', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $holder = ticketPerson(['display_name' => 'Erika Musterfrau', 'username' => 'erika-m']);
    $buyer = ticketPerson(['display_name' => 'Bernd Kaufmann', 'username' => 'bernd-k']);
    $ticket = attendeeTicket($brand, $event['eventId'], holder: $holder, buyer: $buyer, typeName: 'Weekend Pass', overrides: [
        'state' => 'assigned',
        'entry_conditions' => [
            ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'document_hint' => 'Bring a valid student ID'],
            ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'document_hint' => null],
        ],
    ]);
    $door = Checkpoint::factory()->forEvent($event['eventId'])->create(['name' => 'Main door']);
    $device = EntranceDevice::factory()->create(['checkpoint_id' => $door->id, 'label' => 'Scanner 1']);

    // Inserted out of order on purpose: the 16:00 rejection first, so an
    // id-ordered history would put it before the 15:00 scan.
    CheckIn::factory()->onDay($event['dayId'])->rejected(CheckInRejectionReason::ReentryNotPermitted->value)->create([
        'admission_id' => $ticket->id,
        'checkpoint_id' => $door->id,
        'device_id' => $device->id,
        'scanned_at' => CarbonImmutable::parse('2026-10-16 16:00:00', 'UTC'),
    ]);
    CheckIn::factory()->onDay($event['dayId'])->create([
        'admission_id' => $ticket->id,
        'checkpoint_id' => $door->id,
        'device_id' => $device->id,
        'direction' => 'in',
        'conditions_checked' => [['key' => 'student', 'outcome' => 'confirmed']],
        'scanned_at' => CarbonImmutable::parse('2026-10-16 15:00:00', 'UTC'),
    ]);

    [$operator, $admin] = attendeeConsoleOperator($brand);
    Person::query()->updateOrCreate(['id' => $admin->id], ['display_name' => 'Desk Dora', 'username' => 'desk-dora', 'email' => 'dora@desk.test']);

    // The console checks the guest out at 17:00.
    app(CheckInFromConsole::class)->handle($ticket, $event['eventId'], $admin->id, 'out', at: CarbonImmutable::parse('2026-10-16 17:00:00', 'UTC'));

    $this->actingAs($admin)
        ->get(attendeeTicketUrl($operator, $ticket->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/tickets/Show')
            ->where('ticket.admissionId', $ticket->id)
            ->where('ticket.reference', TicketReference::of($ticket->id))
            ->where('ticket.state', 'assigned')
            ->where('ticket.eventId', $event['eventId'])
            ->where('ticket.eventName', 'Kon Fest 2026')
            ->where('ticket.holderUserId', $holder->id)
            ->where('ticket.holderUsername', 'erika-m')
            ->where('ticket.buyerUserId', $buyer->id)
            ->where('ticket.buyerUsername', 'bernd-k')
            ->where('ticket.ticketType', 'Weekend Pass')
            ->where('ticket.registrationId', $ticket->registration_id)
            ->where('ticket.registrationTypeName', 'Weekend Pass')
            ->where('ticket.registrationStatus', 'confirmed')
            // Derivation: three rows, by scanned_at: 15:00 door in, 16:00
            // door rejection, 17:00 console out.
            ->has('ticket.history', 3)
            ->where('ticket.history.0.scannedAt', ticketDetailInstant('2026-10-16 15:00:00'))
            ->where('ticket.history.0.result', 'accepted')
            ->where('ticket.history.0.source', 'door')
            ->where('ticket.history.0.checkpointName', 'Main door')
            ->where('ticket.history.0.deviceLabel', 'Scanner 1')
            ->where('ticket.history.0.actorName', null)
            ->where('ticket.history.1.scannedAt', ticketDetailInstant('2026-10-16 16:00:00'))
            ->where('ticket.history.1.result', 'rejected')
            ->where('ticket.history.1.reason', CheckInRejectionReason::ReentryNotPermitted->consoleSentence())
            ->where('ticket.history.2.scannedAt', ticketDetailInstant('2026-10-16 17:00:00'))
            ->where('ticket.history.2.direction', 'out')
            ->where('ticket.history.2.source', 'console')
            ->where('ticket.history.2.checkpointName', 'Console')
            ->where('ticket.history.2.deviceLabel', null)
            ->where('ticket.history.2.actorUserId', $admin->id)
            ->where('ticket.history.2.actorName', 'Desk Dora')
            // Derivation: `student` was confirmed on the 15:00 scan; `press`
            // is never asked at the door, so it has no outcome.
            ->where('ticket.doorChecks', [
                ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'documentHint' => 'Bring a valid student ID', 'outcome' => 'confirmed'],
                ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'documentHint' => null, 'outcome' => null],
            ])
            // Derivation: today's latest accepted row is the 17:00 `out`, so
            // in is offered and out is not; `student` was answered today, so
            // nothing is owed.
            ->where('ticket.canCheckIn', true)
            ->where('ticket.canCheckOut', false)
            ->where('ticket.owedDoorChecks', [])
            // No `admission.print-badge` on this admin.
            ->where('ticket.badgeUrl', null));
});

it('links the badge only for print-badge, and shows a crew ticket without a registration', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $crew = attendeeTicket($brand, $event['eventId'], holder: ticketPerson(), overrides: ['registration_id' => null, 'role' => 'crew']);
    [$operator, $admin] = attendeeConsoleOperator($brand, ['platform.access-admin', 'admission.check-in-attendees', 'admission.print-badge']);

    $this->actingAs($admin)
        ->get(attendeeTicketUrl($operator, $crew->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.badgeUrl', "/admin/admissions/{$crew->id}/badge")
            ->where('ticket.registrationId', null)
            ->where('ticket.registrationStatus', null)
            ->where('ticket.ticketType', 'crew')
            ->where('ticket.history', [])
            // Derivation: issued, never in — in is offered, out is not.
            ->where('ticket.canCheckIn', true)
            ->where('ticket.canCheckOut', false));
});

it('is 403 without admission.check-in-attendees', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $ticket = attendeeTicket($brand, $event['eventId']);
    [$operator, $admin] = attendeeConsoleOperator($brand, ['platform.access-admin', 'admission.print-badge']);

    $this->actingAs($admin)
        ->get(attendeeTicketUrl($operator, $ticket->id))
        ->assertForbidden();
});

it('is 404 for a ticket that does not exist', function (): void {
    $brand = $this->withBrand();
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->get(attendeeTicketUrl($operator, (string) Str::ulid()))
        ->assertNotFound();
});

it('words an outside-event-day refusal in the history by the row\'s direction', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $ticket = attendeeTicket($brand, $event['eventId'], holder: ticketPerson(), overrides: ['state' => 'assigned']);

    // Two rejected rows with no day, as the core writes outside every day: a
    // check-in at 15:00 and a check-out at 16:00.
    CheckIn::factory()->rejected(CheckInRejectionReason::OutsideEventDay->value)->create([
        'admission_id' => $ticket->id,
        'direction' => 'in',
        'scanned_at' => CarbonImmutable::parse('2026-10-16 15:00:00', 'UTC'),
    ]);
    CheckIn::factory()->rejected(CheckInRejectionReason::OutsideEventDay->value)->create([
        'admission_id' => $ticket->id,
        'direction' => 'out',
        'scanned_at' => CarbonImmutable::parse('2026-10-16 16:00:00', 'UTC'),
    ]);

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: by scanned_at, the refused check-in then the refused
    // check-out; each reads its own direction (#329 final review M2).
    $this->actingAs($admin)
        ->get(attendeeTicketUrl($operator, $ticket->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('ticket.history', 2)
            ->where('ticket.history.0.direction', 'in')
            ->where('ticket.history.0.reason', 'There is no event day today, so nobody can be checked in right now.')
            ->where('ticket.history.1.direction', 'out')
            ->where('ticket.history.1.reason', 'There is no event day today, so nobody can be checked out right now.'));
});
