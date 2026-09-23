<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Platform\Models\Operator;

/**
 * The console check-in and check-out over HTTP (#329, attendee-list design
 * §4.3, §5, §7, D-ATT-03, D-ATT-04): the controller translates, the core
 * decides. Every test posts directly, never through the dialog, so a client
 * that ignores a disabled button is exactly what is exercised.
 *
 * Every test runs at 2026-10-16 18:00 UTC, and `attendeeFixture()` gives the
 * event one calendar day, 2026-10-16, with unlimited re-entry. Every
 * brand-owned row is created before the first admin-host request, and every
 * read after one escapes `BrandScope`, which reads nothing there
 * (.ai/rules/tests.md). Local helpers are prefixed `consoleRoutes*`.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
});

/**
 * A student-ticket and an over-18 check, both asked at the door, and a press
 * condition the door never asks about.
 *
 * @return list<array{key: string, label: string, verification: string, document_hint: string|null}>
 */
function consoleRoutesConditions(): array
{
    return [
        ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'document_hint' => 'Bring a valid student ID'],
        ['key' => 'adult', 'label' => 'Over 18', 'verification' => 'document_at_door', 'document_hint' => null],
        ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'document_hint' => null],
    ];
}

function consoleRoutesRows(Admission $admission): int
{
    return CheckIn::withoutBrandScope()->where('admission_id', $admission->id)->count();
}

it('checks a holder in with the admin as actor and opens the hub, then checks them out', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $holder = ticketPerson();
    $admission = Admission::factory()->assigned($holder->id)->create(['event_id' => $event['eventId']]);
    [$operator, $admin] = attendeeConsoleOperator($brand);
    $page = attendeeTicketUrl($operator, $admission->id);

    $this->actingAs($admin)
        ->from($page)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))
        ->assertSessionHasNoErrors()
        ->assertRedirect($page);

    $row = CheckIn::withoutBrandScope()->where('admission_id', $admission->id)->sole();

    // Derivation: a live ticket with no conditions, not in today, on a
    // covered day with unlimited re-entry — the core accepts it, and the
    // actor is the signed-in admin.
    expect($row->result)->toBe(CheckInResult::Accepted)
        ->and($row->direction)->toBe('in')
        ->and($row->actor_user_id)->toBe($admin->id)
        ->and($row->event_day_id)->toBe($event['dayId']);

    $presence = EventPresence::withoutBrandScope()->where('user_id', $holder->id)->sole();
    expect($presence->hub_access)->toBeTrue()
        ->and($presence->last_direction)->toBe('in');

    $this->app->forgetScopedInstances();
    $this->travel(1)->hours();

    $this->actingAs($admin)
        ->from($page)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-out'))
        ->assertSessionHasNoErrors()
        ->assertRedirect($page);

    // Derivation: the latest accepted row today is the `in` an hour ago, so
    // `out` is allowed and accepted; the presence flips to out.
    expect(EventPresence::withoutBrandScope()->where('user_id', $holder->id)->sole()->last_direction)->toBe('out')
        ->and(consoleRoutesRows($admission))->toBe(2);
});

it('stores confirmed and refused door checks on the accepted row', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleRoutesConditions(),
    ]);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'), [
            'conditions' => [
                ['key' => 'student', 'outcome' => 'confirmed'],
                ['key' => 'adult', 'outcome' => 'refused'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $row = CheckIn::withoutBrandScope()->where('admission_id', $admission->id)->sole();

    // Derivation: both owed checks answered; a refusal still checks in, as
    // at the door (ADR-0066 §5, T-8), and both answers ride on the row in
    // the order posted.
    expect($row->result)->toBe(CheckInResult::Accepted)
        ->and($row->conditions_checked)->toBe([
            ['key' => 'student', 'outcome' => 'confirmed'],
            ['key' => 'adult', 'outcome' => 'refused'],
        ]);
});

it('refuses a check-in with an owed check unanswered or an unknown one, and writes nothing', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleRoutesConditions(),
    ]);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: owed today are `student` and `adult`; only `student` is
    // answered, so the core refuses naming the one left — "Over 18".
    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'), [
            'conditions' => [['key' => 'student', 'outcome' => 'confirmed']],
        ])
        ->assertSessionHasErrors(['check_in' => 'Answer every door check before checking this ticket in: Over 18.']);

    $this->app->forgetScopedInstances();

    // `press` is not a door check, so the ticket does not owe it.
    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'), [
            'conditions' => [
                ['key' => 'student', 'outcome' => 'confirmed'],
                ['key' => 'adult', 'outcome' => 'confirmed'],
                ['key' => 'press', 'outcome' => 'confirmed'],
            ],
        ])
        ->assertSessionHasErrors('conditions.2.key');

    expect(consoleRoutesRows($admission))->toBe(0);
});

it('answers a revoked ticket with the core\'s sentence and one rejected row', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->revoked()->create(['event_id' => $event['eventId']]);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))
        ->assertRedirect()
        ->assertSessionHasErrors(['check_in' => CheckInRejectionReason::AdmissionNotValid->consoleSentence()]);

    $row = CheckIn::withoutBrandScope()->where('admission_id', $admission->id)->sole();

    // Derivation: revoked fails `EvaluateAdmissionValidity`, so the core
    // writes the door's own rejection, exactly once.
    expect($row->result)->toBe(CheckInResult::Rejected)
        ->and($row->reason_code)->toBe(CheckInRejectionReason::AdmissionNotValid->value);
});

it('refuses an already-in ticket without writing a row', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);
    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in');
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))
        ->assertSessionHasErrors(['check_in' => 'This ticket is already checked in.']);

    // Derivation: the one row is the check-in above; the refusal adds none.
    expect(consoleRoutesRows($admission))->toBe(1);
});

it('is 403 on both writes without admission.check-in-attendees', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);
    [$operator, $admin] = attendeeConsoleOperator($brand, ['platform.access-admin']);

    $this->actingAs($admin)->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))->assertForbidden();
    $this->app->forgetScopedInstances();
    $this->actingAs($admin)->post(attendeeTicketUrl($operator, $admission->id, 'check-out'))->assertForbidden();

    expect(consoleRoutesRows($admission))->toBe(0);
});

it('is 404 on the page and both writes for another operator\'s ticket', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);
    // In, so a check-out would be a valid request too: the 404 is the scope.
    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in');
    $other = attendeeTicket($brand, $event['eventId'], holder: ticketPerson());

    [$stranger, $strangerAdmin] = attendeeConsoleOperator($brand, operator: Operator::factory()->create(), host: 'admin.elsewhere.test');

    $this->actingAs($strangerAdmin)->get(attendeeTicketUrl($stranger, $admission->id))->assertNotFound();
    $this->app->forgetScopedInstances();
    $this->actingAs($strangerAdmin)->post(attendeeTicketUrl($stranger, $other->id, 'check-in'))->assertNotFound();
    $this->app->forgetScopedInstances();
    $this->actingAs($strangerAdmin)->post(attendeeTicketUrl($stranger, $admission->id, 'check-out'))->assertNotFound();

    // Derivation: only the setup's check-in exists; neither post wrote.
    expect(consoleRoutesRows($admission))->toBe(1)
        ->and(consoleRoutesRows($other))->toBe(0);
});

it('answers the 61st write in a minute with 429', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);
    [$operator, $admin] = attendeeConsoleOperator($brand);
    $url = attendeeTicketUrl($operator, $admission->id, 'check-out');

    // Derivation: `admission-console-check-in` allows 60 per account per
    // minute, and the clock is frozen, so posts 1–60 reach the controller —
    // each a refused check-out (the ticket is not in), a redirect — and
    // post 61 is throttled.
    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->actingAs($admin)->post($url)->assertRedirect();
        $this->app->forgetScopedInstances();
    }

    $this->actingAs($admin)->post($url)->assertStatus(429);
});

it('shows the ticket as in, from the console, on the list after a check-in', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $admission = attendeeTicket($brand, $event['eventId'], holder: ticketPerson());
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))
        ->assertSessionHasNoErrors();

    $this->app->forgetScopedInstances();

    // Derivation: one ticket; its only row is today's console `in`.
    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event['eventId']}/attendees")
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.data.0.admissionId', $admission->id)
            ->where('attendees.data.0.checkIn.direction', 'in')
            ->where('attendees.data.0.checkIn.source', 'console')
            ->where('attendees.data.0.canCheckIn', false)
            ->where('attendees.data.0.canCheckOut', true));
});

it('answers re-entry under once_only with the core\'s sentence, after a check-in and a check-out', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $dayId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()
        ->policy(ReentryPolicy::OnceOnly)
        ->withDays([['day_id' => $dayId, 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag']])
        ->create(['event_id' => $eventId]);
    $admission = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $eventId]);
    $actorId = (string) Str::ulid();

    // 2026-10-16 18:00 UTC is the event's one day: in, then out.
    app(CheckInFromConsole::class)->handle($admission, $eventId, $actorId, 'in');
    app(CheckInFromConsole::class)->handle($admission, $eventId, $actorId, 'out');

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: the ticket is out, so the console's own "already in" does
    // not apply; the core sees one accepted `in` already and, under
    // once_only, refuses a second entry as reentry_not_permitted.
    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $admission->id, 'check-in'))
        ->assertRedirect()
        ->assertSessionHasErrors(['check_in' => CheckInRejectionReason::ReentryNotPermitted->consoleSentence()]);

    $rejected = CheckIn::withoutBrandScope()->where('admission_id', $admission->id)->where('result', CheckInResult::Rejected)->sole();

    // Derivation: the accepted in and out, plus the one rejected row.
    expect($rejected->reason_code)->toBe(CheckInRejectionReason::ReentryNotPermitted->value)
        ->and(consoleRoutesRows($admission))->toBe(3);
});

it('answers outside every event day with the core\'s sentence, never "already in" or "not in"', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $wasIn = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);
    $neverIn = Admission::factory()->assigned(ticketPerson()->id)->create(['event_id' => $event['eventId']]);

    // In on the event's one day, 2026-10-16.
    app(CheckInFromConsole::class)->handle($wasIn, $event['eventId'], (string) Str::ulid(), 'in');

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // 2026-10-18 18:00 UTC: the calendar has no day for it.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 18:00:00', 'UTC'));
    // Derivation: read across every day, $wasIn is in (a check-in would have
    // been "already in") and $neverIn is not (a check-out would have been
    // "not in"). Outside every day both pre-checks are skipped, and the core
    // rejects each as outside_event_day, with that reason's sentence for the
    // row's direction (#329 final review M2): a refused check-out says
    // "checked out", never "checked in".
    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $wasIn->id, 'check-in'))
        ->assertSessionHasErrors(['check_in' => 'There is no event day today, so nobody can be checked in right now.']);

    $this->app->forgetScopedInstances();

    $this->actingAs($admin)
        ->post(attendeeTicketUrl($operator, $neverIn->id, 'check-out'))
        ->assertSessionHasErrors(['check_in' => 'There is no event day today, so nobody can be checked out right now.']);

    // Derivation: $wasIn has its accepted in plus one rejected row; $neverIn
    // has the one rejected row.
    expect(consoleRoutesRows($wasIn))->toBe(2)
        ->and(consoleRoutesRows($neverIn))->toBe(1)
        ->and(CheckIn::withoutBrandScope()->where('admission_id', $neverIn->id)->sole()->reason_code)
        ->toBe(CheckInRejectionReason::OutsideEventDay->value);
});
