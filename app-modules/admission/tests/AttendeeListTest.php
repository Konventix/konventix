<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\Person;
use Konventix\Admission\Support\TicketReference;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\MaterializeOperatorAdminRole;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Spatie\Permission\PermissionRegistrar;

/**
 * The attendee list (#329, attendee-list design §4.1, §4.2, §5, §7): one row
 * per ticket, one search over every listed field, the four filters, the
 * check-in source, and access — the capability first (403), then the
 * operator (404).
 *
 * Every test runs at 2026-10-16 18:00 UTC, and `attendeeFixture()` gives the
 * event one calendar day, 2026-10-16, so "today" is that day and a check-in
 * row counts for the list when its `event_day_id` is that day's id.
 *
 * Every brand-owned row is created before the first admin-host request, which
 * resolves no brand (.ai/rules/tests.md). Helpers local to this file are
 * prefixed `attendeeList*` (Pest helpers are process-global).
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
});

function attendeeListUrl(Operator $operator, string $eventId, array $query = []): string
{
    $url = "http://{$operator->admin_host}/admin/events/{$eventId}/attendees";

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

/**
 * Three tickets whose every searchable value is distinct, so each search term
 * below hits exactly one of them:
 *
 * - `a`: holder Erika Musterfrau (`erika-m`, erika@holders.test), bought by
 *   Bernd Kaufmann (`bernd-k`, bernd@buyers.test), type "Weekend Pass".
 * - `b`: holder Hans Zimmer (`hans-z`, hans@holders.test), bought by Clara
 *   Einkauf (`clara-e`, clara@buyers.test), type "Day Ticket".
 * - `c`: no holder, bought by Dora Order (`dora-o`, dora@buyers.test), type
 *   "Student Pass".
 *
 * The ids are ULIDs: their last eight characters (the reference) are random,
 * so no two tickets share a reference or contain one another's id.
 *
 * @return array{a: Admission, b: Admission, c: Admission}
 */
function attendeeListSearchTrio(Brand $brand, string $eventId): array
{
    $person = fn (string $name, string $username, string $email): Person => ticketPerson([
        'display_name' => $name,
        'username' => $username,
        'email' => $email,
    ]);

    return [
        'a' => attendeeTicket(
            $brand,
            $eventId,
            holder: $person('Erika Musterfrau', 'erika-m', 'erika@holders.test'),
            buyer: $person('Bernd Kaufmann', 'bernd-k', 'bernd@buyers.test'),
            typeName: 'Weekend Pass',
        ),
        'b' => attendeeTicket(
            $brand,
            $eventId,
            holder: $person('Hans Zimmer', 'hans-z', 'hans@holders.test'),
            buyer: $person('Clara Einkauf', 'clara-e', 'clara@buyers.test'),
            typeName: 'Day Ticket',
        ),
        'c' => attendeeTicket(
            $brand,
            $eventId,
            buyer: $person('Dora Order', 'dora-o', 'dora@buyers.test'),
            typeName: 'Student Pass',
        ),
    ];
}

/**
 * The term each single-hit case types, derived from
 * `attendeeListSearchTrio()`'s values.
 *
 * @param  array{a: Admission, b: Admission, c: Admission}  $trio
 */
function attendeeListSearchTerm(string $case, array $trio): string
{
    return match ($case) {
        'holder name' => 'musterfrau',
        'holder username' => 'hans-z',
        'holder email' => 'erika@holders',
        'buyer name' => 'Einkauf',
        'buyer username' => 'dora-o',
        'buyer email' => 'bernd@buyers',
        'reference with hyphen' => TicketReference::of($trio['b']->id),
        'reference without hyphen' => str_replace('-', '', TicketReference::of($trio['c']->id)),
        'reference in lower case' => strtolower(TicketReference::of($trio['a']->id)),
        'full admission id' => $trio['c']->id,
        'full admission id in upper case' => strtoupper($trio['b']->id),
        'registration id' => (string) $trio['b']->registration_id,
        'type label' => 'weekend',
    };
}

/**
 * Each case: the field, the one ticket it must find, and why no other ticket
 * of the three matches.
 */
dataset('attendee search terms', [
    ['holder name', 'a', 'only a\'s holder is called Musterfrau'],
    ['holder username', 'b', 'only b\'s holder has the handle hans-z'],
    ['holder email', 'a', 'only a\'s holder mails from erika@'],
    ['buyer name', 'b', 'only b\'s buyer is Clara Einkauf'],
    ['buyer username', 'c', 'only c\'s buyer has the handle dora-o'],
    ['buyer email', 'a', 'only a\'s buyer mails from bernd@'],
    ['reference with hyphen', 'b', 'b\'s reference as printed; searchFragment() drops the hyphen, and only b\'s id ends in those eight characters'],
    ['reference without hyphen', 'c', 'the eight tail characters only c\'s id ends with'],
    ['reference in lower case', 'a', 'a\'s reference typed lower-case, the way ids are stored (HasUlids lower-cases them)'],
    ['full admission id', 'c', 'a whole ULID is contained only in itself'],
    ['full admission id in upper case', 'b', 'ids are stored lower-case, so only ILIKE (not LIKE) lets b\'s id typed upper-case find b'],
    ['registration id', 'b', 'each ticket has its own random registration ULID'],
    ['type label', 'a', 'only a\'s type is "Weekend Pass"'],
]);

it('finds exactly one ticket per searched field', function (string $case, string $expected, string $why): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $trio = attendeeListSearchTrio($brand, $event['eventId']);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => attendeeListSearchTerm($case, $trio)]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/attendees/Index')
            // Derivation: 3 tickets seeded, 1 expected — see $why.
            ->where('attendees.total', 1)
            ->where('attendees.data.0.admissionId', $trio[$expected]->id));
})->with('attendee search terms');

it('returns nothing for a term no field contains', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    attendeeListSearchTrio($brand, $event['eventId']);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: no name, handle, email, id or type of the three contains
    // "zeppelin", so 0 of 3.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => 'zeppelin']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('attendees.total', 0)->has('attendees.data', 0));
});

it('matches % and _ literally rather than as wildcards', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    attendeeListSearchTrio($brand, $event['eventId']);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: unescaped, `%` would match all 3 and `_` every non-empty
    // value; escaped, they match only a literal `%` or `_`, which no value of
    // the three contains (handles use hyphens, ULIDs are alphanumeric), so 0.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => '%']))
        ->assertInertia(fn (Assert $page) => $page->where('attendees.total', 0));

    $this->app->forgetScopedInstances();

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => '_']))
        ->assertInertia(fn (Assert $page) => $page->where('attendees.total', 0));
});

it('matches a backslash literally', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    attendeeListSearchTrio($brand, $event['eventId']);
    $slashed = attendeeTicket($brand, $event['eventId'], holder: ticketPerson(['display_name' => 'Tom Back\\Slash']));
    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: the term `k\S` escaped is `k\\S`, a literal backslash, so
    // it matches "Tom Back\Slash" only (none of the trio contains a
    // backslash): 1 of 4. Unescaped, `\S` would read as an escaped `S`, the
    // pattern would be `%kS%`, and "Tom Back\Slash" would not be found.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => 'k\\S']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 1)
            ->where('attendees.data.0.admissionId', $slashed->id));
});

it('partitions the list into checked in and not checked in, on today only', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $in = attendeeTicket($brand, $event['eventId']);
    $out = attendeeTicket($brand, $event['eventId']);
    $never = attendeeTicket($brand, $event['eventId']);
    $yesterday = attendeeTicket($brand, $event['eventId']);

    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $in->id, 'direction' => 'in', 'scanned_at' => now()->subHour()]);
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $out->id, 'direction' => 'in', 'scanned_at' => now()->subHours(2)]);
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $out->id, 'direction' => 'out', 'scanned_at' => now()->subHour()]);
    // An `in` on another day of the event does not make the ticket in today
    // (`LatestAcceptedCheckIn`, Task 3 review I1).
    CheckIn::factory()->onDay((string) Str::ulid())->create(['admission_id' => $yesterday->id, 'direction' => 'in', 'scanned_at' => now()->subDay()]);

    // Rows the list must never show (Task 4 review I1), each checked in today
    // so that dropping a scope would add it to `in`: a ticket of ANOTHER
    // event of the same brand, and a ticket of ANOTHER brand carrying this
    // very event id (the only way the brand condition is what excludes it).
    $otherEvent = attendeeTicket($brand, (string) Str::ulid());
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $otherEvent->id, 'direction' => 'in', 'scanned_at' => now()->subMinutes(10)]);

    $otherBrand = Brand::factory()->create();
    $this->withBrand($otherBrand);
    $otherBrandTicket = attendeeTicket($otherBrand, $event['eventId']);
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $otherBrandTicket->id, 'direction' => 'in', 'scanned_at' => now()->subMinutes(10)]);
    $this->withBrand($brand);

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: the list is this brand's tickets of this event — $in, $out,
    // $never, $yesterday — so $otherEvent (wrong event) and $otherBrandTicket
    // (wrong brand) are in neither set, although both are `in` today. Today's
    // latest accepted direction is `in` for $in, `out` for $out, none for
    // $never and none for $yesterday (its row is another day's). So `in` =
    // {$in} (1) and `not_in` = {$out, $never, $yesterday} (3); 1 + 3 = the 4
    // tickets, and the two sets share none. With either scope dropped, `in`
    // would read 2 or 3.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['presence' => 'in']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 1)
            ->where('attendees.data.0.admissionId', $in->id)
            ->where('attendees.data.0.checkIn.direction', 'in')
            ->where('filters.presence', 'in'));

    $this->app->forgetScopedInstances();

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['presence' => 'not_in']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 3)
            ->where('attendees.data', fn ($rows): bool => collect($rows)->pluck('admissionId')->sort()->values()->all()
                === collect([$out->id, $never->id, $yesterday->id])->sort()->values()->all()));
});

it('narrows to unassigned tickets and to revoked tickets', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $held = attendeeTicket($brand, $event['eventId'], holder: ticketPerson());
    $unheld = attendeeTicket($brand, $event['eventId'], buyer: ticketPerson());
    $revoked = attendeeTicket($brand, $event['eventId'], holder: ticketPerson(), overrides: ['state' => 'revoked']);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: of $held, $unheld and $revoked only $unheld has no holder.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['view' => 'unassigned']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 1)
            ->where('attendees.data.0.admissionId', $unheld->id));

    $this->app->forgetScopedInstances();

    // Derivation: only $revoked is revoked; $held and $unheld are issued.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['view' => 'revoked']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 1)
            ->where('attendees.data.0.admissionId', $revoked->id)
            ->where('attendees.data.0.state', 'revoked')
            ->where('attendees.data.0.canCheckIn', false));

});

it('shows an unassigned ticket with its buyer, and a person with two tickets twice', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $buyer = ticketPerson(['display_name' => 'Dora Order', 'username' => 'dora-o', 'email' => 'dora@buyers.test']);
    $twice = ticketPerson(['display_name' => 'Erika Musterfrau']);
    $unheld = attendeeTicket($brand, $event['eventId'], buyer: $buyer);
    $first = attendeeTicket($brand, $event['eventId'], holder: $twice);
    $second = attendeeTicket($brand, $event['eventId'], holder: $twice);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['sort' => 'name', 'dir' => 'asc']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($unheld, $first, $second, $twice): void {
            // Derivation: 3 tickets, one row each (D-ATT-01). By name
            // ascending, coalesce(holder, buyer): "Dora Order" (the buyer of
            // the unheld one) < "Erika Musterfrau" twice, so the unheld
            // ticket is row 0 and Erika's two tickets are rows 1 and 2 (their
            // order between them is the admission id, the tiebreak).
            $page->where('attendees.total', 3)
                ->where('attendees.data.0.admissionId', $unheld->id)
                ->where('attendees.data.0.holderUserId', null)
                ->where('attendees.data.0.holderName', null)
                ->where('attendees.data.0.buyerName', 'Dora Order')
                ->where('attendees.data.0.email', 'dora@buyers.test')
                ->where('attendees.data.1.holderUserId', $twice->id)
                ->where('attendees.data.2.holderUserId', $twice->id)
                ->where('attendees.data', fn ($rows): bool => collect($rows)->pluck('admissionId')->slice(1)->sort()->values()->all()
                    === collect([$first->id, $second->id])->sort()->values()->all());
        });
});

it('sorts by latest activity, newest first and never-checked-in last, by default', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $early = attendeeTicket($brand, $event['eventId']);
    $late = attendeeTicket($brand, $event['eventId']);
    $never = attendeeTicket($brand, $event['eventId']);

    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $early->id, 'scanned_at' => now()->subHours(3)]);
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $late->id, 'scanned_at' => now()->subHour()]);

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: no `sort` given, so activity descending, nulls last:
    // $late (17:00 UTC) before $early (15:00 UTC), then $never (no row).
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'activity')
            ->where('filters.dir', 'desc')
            ->where('attendees.data.0.admissionId', $late->id)
            ->where('attendees.data.1.admissionId', $early->id)
            ->where('attendees.data.2.admissionId', $never->id)
            ->where('attendees.data.2.checkIn.direction', null));
});

it('says console for a console check-in and door for a device scan', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $atConsole = attendeeTicket($brand, $event['eventId']);
    $atDoor = attendeeTicket($brand, $event['eventId']);
    $door = Checkpoint::factory()->forEvent($event['eventId'])->create();

    app(CheckInFromConsole::class)->handle($atConsole, $event['eventId'], (string) Str::ulid(), 'in');
    CheckIn::factory()->onDay($event['dayId'])->create([
        'admission_id' => $atDoor->id,
        'checkpoint_id' => $door->id,
        'scanned_at' => now()->subMinutes(5),
    ]);

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // Derivation: the console check-in is at 18:00 and the door scan at
    // 17:55, so by activity descending the console row is first.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.data.0.admissionId', $atConsole->id)
            ->where('attendees.data.0.checkIn.direction', 'in')
            ->where('attendees.data.0.checkIn.source', 'console')
            ->where('attendees.data.0.canCheckIn', false)
            ->where('attendees.data.0.canCheckOut', true)
            ->where('attendees.data.1.admissionId', $atDoor->id)
            ->where('attendees.data.1.checkIn.source', 'door'));
});

it('carries the ids the page links to, the reference, the type and the registration status', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand, 'SXLan 2027');
    $holder = ticketPerson(['display_name' => 'Erika Musterfrau', 'username' => 'erika-m', 'email' => 'erika@holders.test']);
    $buyer = ticketPerson();
    $ticket = attendeeTicket($brand, $event['eventId'], holder: $holder, buyer: $buyer, typeName: 'Weekend Pass', status: 'unconfirmed', overrides: [
        'entry_conditions' => [
            ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'document_hint' => 'Bring a valid student ID'],
            ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'document_hint' => null],
        ],
    ]);
    $crew = attendeeTicket($brand, $event['eventId'], holder: ticketPerson(), overrides: ['registration_id' => null, 'role' => 'crew']);
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => 'erika-m']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.id', $event['eventId'])
            ->where('event.name', 'SXLan 2027')
            ->where('canPrintBadge', false)
            // The page turns these into admin.users.edit, the ticket page and
            // admin.registrations.show (D-ATT-05).
            ->where('attendees.data.0.admissionId', $ticket->id)
            ->where('attendees.data.0.holderUserId', $holder->id)
            ->where('attendees.data.0.holderUsername', 'erika-m')
            ->where('attendees.data.0.buyerUserId', $buyer->id)
            ->where('attendees.data.0.registrationId', $ticket->registration_id)
            ->where('attendees.data.0.registrationShortId', strtoupper(substr((string) $ticket->registration_id, -8)))
            ->where('attendees.data.0.registrationStatus', 'unconfirmed')
            ->where('attendees.data.0.reference', TicketReference::of($ticket->id))
            ->where('attendees.data.0.ticketType', 'Weekend Pass')
            ->where('attendees.data.0.email', 'erika@holders.test')
            // Derivation: of the two conditions only `student` is checked at
            // the door, and nothing answered it today, so one owed check.
            ->where('attendees.data.0.owedDoorChecks', [
                ['key' => 'student', 'label' => 'Student ticket', 'documentHint' => 'Bring a valid student ID'],
            ]));

    $this->app->forgetScopedInstances();

    // A crew badge has no registration: no id, and its role as the type.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId'], ['search' => $crew->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.data.0.registrationId', null)
            ->where('attendees.data.0.registrationShortId', null)
            ->where('attendees.data.0.registrationStatus', null)
            ->where('attendees.data.0.ticketType', 'crew'));
});

it('is 403 without admission.check-in-attendees', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    [$operator, $admin] = attendeeConsoleOperator($brand, ['platform.access-admin']);

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertForbidden();
});

it('opens for the operator\'s Operator Admin after permissions:sync, which holds the capability', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $operator = Operator::query()->findOrFail($brand->operator_id);
    $operator->forceFill(['admin_host' => 'admin.attendees.test'])->save();

    $this->artisan('permissions:sync')->assertSuccessful();

    $role = Role::query()
        ->where('brand_id', $operator->getKey())
        ->where('default_key', MaterializeOperatorAdminRole::DEFAULT_KEY)
        ->sole();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);
    $registrar->forgetCachedPermissions();

    expect(PermissionModel::query()->where('name', 'admission.check-in-attendees')->exists())->toBeTrue()
        ->and($role->hasPermissionTo('admission.check-in-attendees'))->toBeTrue()
        // D-ATT-06's Einlass role is deferred to #334: nothing ships it.
        ->and(Role::query()->where('default_key', 'admission.einlass')->exists())->toBeFalse();

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/attendees/Index'));
});

it('is 404 for another operator\'s event, even holding the capability', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    attendeeTicket($brand, $event['eventId'], holder: ticketPerson());

    $stranger = Operator::factory()->create();
    [$strangerOperator, $strangerAdmin] = attendeeConsoleOperator($brand, operator: $stranger, host: 'admin.elsewhere.test');

    $this->actingAs($strangerAdmin)
        ->get(attendeeListUrl($strangerOperator, $event['eventId']))
        ->assertNotFound();
});

it('is 404 for an event Admission has no summary of', function (): void {
    $brand = $this->withBrand();
    [$operator, $admin] = attendeeConsoleOperator($brand);

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, (string) Str::ulid()))
        ->assertNotFound();
});

it('has no module-off case, because Admission is Core and cannot be switched off', function (): void {
    // Stands in for design §7's "module off → 404": there is no off.
    expect(app(ModuleGate::class)->isGateable('admission'))->toBeFalse();
});

it('marks the check-in state last known when no event day covers now', function (): void {
    $brand = $this->withBrand();
    $event = attendeeFixture($brand);
    $wasIn = attendeeTicket($brand, $event['eventId']);
    $never = attendeeTicket($brand, $event['eventId'], overrides: [
        'entry_conditions' => [
            ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'document_hint' => null],
        ],
    ]);
    CheckIn::factory()->onDay($event['dayId'])->create(['admission_id' => $wasIn->id, 'direction' => 'in', 'scanned_at' => now()]);

    [$operator, $admin] = attendeeConsoleOperator($brand);

    // 2026-10-18 has no day in the calendar (its only day is 2026-10-16), so
    // the list falls back to every accepted row and says so.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 18:00:00', 'UTC'));

    // Derivation: $wasIn's latest accepted row across every day is the `in`
    // of 2026-10-16 18:00, so it reads `in`, last known; $never has none.
    // By activity descending, nulls last: $wasIn, then $never. $never could
    // check in and carries a door check, yet owes none here — the core would
    // refuse any check-in as outside_event_day.
    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('outsideEventDay', true)
            ->where('attendees.data.0.admissionId', $wasIn->id)
            ->where('attendees.data.0.checkIn.direction', 'in')
            ->where('attendees.data.0.checkIn.lastKnown', true)
            ->where('attendees.data.1.admissionId', $never->id)
            ->where('attendees.data.1.checkIn.direction', null)
            ->where('attendees.data.1.owedDoorChecks', []));

    $this->app->forgetScopedInstances();

    // Back on the event day, the same row is today's state, not last known.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 19:00:00', 'UTC'));

    $this->actingAs($admin)
        ->get(attendeeListUrl($operator, $event['eventId']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('outsideEventDay', false)
            ->where('attendees.data.0.checkIn.lastKnown', false));
});
