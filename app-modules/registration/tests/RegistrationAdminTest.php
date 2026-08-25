<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Event\Events\EventCreated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Registration\Actions\UpdateBillingParty;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Spatie\Permission\PermissionRegistrar;

function registrationsOperator(array $permissions = ['platform.access-admin', 'registration.view-registrations', 'registration.approve-registrations', 'registration.export-registrations']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.regs.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Operator Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * Registration's own view of an event, projected from Event's contract event
 * (ADR-0004). Takes the brand id rather than the model: a plain Pest helper
 * runs in global scope and cannot call the TestCase's protected withBrand()
 * (.ai/rules/tests.md).
 */
function registrationsAdminEvent(string $brandId, string $slug, string $name): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: $slug,
        name: $name,
        startsAt: '2027-05-14 18:00:00',
        endsAt: '2027-05-16 14:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return $eventId;
}

it('lists registrations with registrant read-model names and bulk-approves', function (): void {
    [, $admin] = registrationsOperator();

    $brand = $this->withBrand();

    $registrantId = (string) Str::ulid();
    event(new UserRegistered($registrantId, $brand->getKey(), 'ada@example.test', 'Ada Lovelace', 'de', now()->toIso8601String()));

    $type = RegistrationType::factory()->requiringApproval()->create();
    $registration = Registration::factory()->forType($type)->forRegistrant($registrantId)->create();

    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/registrations/Index')
            ->has('registrations.data', 1)
            ->where('registrations.data.0.registrant', 'Ada Lovelace')
            ->where('registrations.data.0.approvable', true));

    $this->actingAs($admin)
        ->post('http://admin.regs.test/admin/registrations/bulk-approve', ['ids' => [$registration->id]])
        ->assertRedirect();

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Approved);
});

it('exports the filtered registration list as CSV', function (): void {
    [, $admin] = registrationsOperator();

    $this->withBrand();
    Registration::factory()->withBillingParty()->create(['organization_name' => 'Musterfirma GmbH']);

    $response = $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations/export')
        ->assertOk();

    expect($response->streamedContent())->toContain('Musterfirma GmbH');
});

it('freezes the billing party once InvoiceIssued lands for the registration', function (): void {
    $this->withBrand();

    $registration = Registration::factory()->withBillingParty()->create();

    event(new InvoiceIssued(
        invoiceId: (string) Str::ulid(),
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: 'registration',
        settlesId: $registration->id,
        invoiceNumber: 'TEST-2026-00001',
        issuedOn: now()->toDateString(),
        dueOn: now()->addDays(30)->toDateString(),
        totalGross: '297.50',
        currency: 'EUR',
    ));

    expect($registration->refresh()->invoiced_at)->not->toBeNull();

    app(UpdateBillingParty::class)->handle($registration, new SubmitRegistrationData(
        organizationName: 'Andere GmbH',
        billingRecipientName: null,
        billingEmail: null,
    ));
})->throws(ValidationException::class);

it('exports and anonymizes a subject through the GDPR handler, keeping the tombstone rows', function (): void {
    $brand = $this->withBrand();

    $registrantId = (string) Str::ulid();
    $registration = Registration::factory()->withBillingParty()->forRegistrant($registrantId)->create();

    $handler = new RegistrationGdprHandler;

    $export = $handler->exportFor($registrantId, $brand->getKey());
    expect($export['registrations'])->toHaveCount(1)
        ->and($export['registrations'][0]['organization_name'])->not->toBeNull();

    $handler->delete($registrantId, $brand->getKey(), DeletionMode::Anonymize);
    $handler->delete($registrantId, $brand->getKey(), DeletionMode::Anonymize);

    $registration->refresh();
    // Tombstone: the row and its ULID survive, the person does not.
    expect($registration->organization_name)->toBeNull()
        ->and($registration->billing_email)->toBeNull()
        ->and(Registration::query()->whereKey($registration->id)->exists())->toBeTrue();
});

it('drops a status filter value the enum does not know instead of querying it', function (): void {
    [, $admin] = registrationsOperator();

    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations?status=bogus')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', null)
            ->has('statuses'));
});

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = registrationsOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    Registration::factory()->forType(RegistrationType::factory()->create())->create(['organization_name' => 'SXLan Crew']);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Registration::factory()->forType(RegistrationType::factory()->create())->create(['organization_name' => 'Xpertise Crew']);

    $this->actingAs($admin)
        ->put('http://admin.regs.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('registrations.data', 1)
            ->where('registrations.data.0.organization', 'SXLan Crew'));

    // A default, not a scope: "all brands" is a legitimate console view.
    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('registrations.data', 2));
});

it('defaults the event filter to the console selection, offering names from its own read model', function (): void {
    [, $admin] = registrationsOperator();

    $brand = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));

    // ADR-0004: Registration learns which events exist from Event's contract
    // event, never from Event's tables.
    $selected = registrationsAdminEvent($brand->getKey(), 'sxlan-2027', 'SXLan 2027');
    $other = registrationsAdminEvent($brand->getKey(), 'sxlan-2028', 'SXLan 2028');

    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($selected)->create())
        ->create(['organization_name' => 'Crew 2027']);
    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($other)->create())
        ->create(['organization_name' => 'Crew 2028']);

    // The console's event selector is fed by whichever module owns events;
    // this test stands in for it rather than reaching into Event.
    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => [
        ['id' => $selected, 'name' => 'SXLan 2027', 'brandId' => $brand->getKey()],
        ['id' => $other, 'name' => 'SXLan 2028', 'brandId' => $brand->getKey()],
    ]);

    $this->actingAs($admin)
        ->put('http://admin.regs.test/admin/selection', ['brandId' => $brand->getKey(), 'eventId' => $selected])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.event', $selected)
            ->count('registrations.data', 1)
            ->where('registrations.data.0.organization', 'Crew 2027')
            ->where('events', [
                ['id' => $selected, 'name' => 'SXLan 2027'],
                ['id' => $other, 'name' => 'SXLan 2028'],
            ]));

    $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations?event=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.event', null)
            ->count('registrations.data', 2));
});

it('drops the selection event when the list asks for a different brand', function (): void {
    [, $admin] = registrationsOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    $sxlanEvent = registrationsAdminEvent($sxlan->getKey(), 'sxlan-2027', 'SXLan 2027');
    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($sxlanEvent)->create())
        ->create(['organization_name' => 'SXLan Crew']);

    $xpertise = $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Registration::factory()
        ->forType(RegistrationType::factory()->create())
        ->create(['organization_name' => 'Xpertise Crew']);

    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => [
        ['id' => $sxlanEvent, 'name' => 'SXLan 2027', 'brandId' => $sxlan->getKey()],
    ]);

    $this->actingAs($admin)
        ->put('http://admin.regs.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => $sxlanEvent])
        ->assertRedirect();

    // The shell holds SXLan and its 2027 event; the list is asked for
    // Xpertise. The event belongs to the brand that was just replaced, so it
    // must not still filter: `brand=Xpertise AND event=SXLan 2027` is no rows
    // at all, under an event Select that cannot even show what it filtered by.
    $this->actingAs($admin)
        ->get("http://admin.regs.test/admin/registrations?brand={$xpertise->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $xpertise->getKey())
            ->where('filters.event', null)
            ->count('registrations.data', 1)
            ->where('registrations.data.0.organization', 'Xpertise Crew'));
});

it('exports only the rows the query string names — brand, event and status alike', function (): void {
    [, $admin] = registrationsOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    $thisYear = registrationsAdminEvent($sxlan->getKey(), 'sxlan-2027', 'SXLan 2027');
    $nextYear = registrationsAdminEvent($sxlan->getKey(), 'sxlan-2028', 'SXLan 2028');

    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($thisYear)->create())
        ->create(['organization_name' => 'SXLan Crew']);
    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($nextYear)->create())
        ->create(['organization_name' => 'SXLan Next Year']);
    Registration::factory()
        ->forType(RegistrationType::factory()->forEvent($thisYear)->create())
        ->create(['organization_name' => 'SXLan Withdrawn', 'status' => RegistrationStatus::Cancelled]);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Registration::factory()->forType(RegistrationType::factory()->create())->create(['organization_name' => 'Xpertise Crew']);

    $this->actingAs($admin)
        ->put('http://admin.regs.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    $csv = $this->actingAs($admin)
        ->get("http://admin.regs.test/admin/registrations/export?brand={$sxlan->getKey()}&event={$thisYear}&status=submitted")
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('SXLan Crew')
        ->and($csv)->not->toContain('SXLan Next Year')
        ->and($csv)->not->toContain('SXLan Withdrawn')
        ->and($csv)->not->toContain('Xpertise Crew');

    // The cleared filter has to reach the export too: an operator looking at
    // "all brands" who exports must not silently download one brand's rows.
    $everything = $this->actingAs($admin)
        ->get('http://admin.regs.test/admin/registrations/export?brand=all&event=all')
        ->assertOk()
        ->streamedContent();

    expect($everything)->toContain('SXLan Crew')
        ->and($everything)->toContain('SXLan Next Year')
        ->and($everything)->toContain('Xpertise Crew');
});
