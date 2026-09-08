<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: the registration-type and quota console — the offer (ADR-0005),
 * its price declaration (ADR-0017) and the quotas it draws on.
 *
 * Helper name prefixed with the module: Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
function registrationTypeOperator(array $permissions = [
    'platform.access-admin',
    'registration.manage-registration-types',
    'registration.manage-quotas',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.regtypes.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * Takes the brand rather than creating it: a plain Pest helper runs in global
 * scope and cannot call the protected `withBrand()` on the TestCase
 * (.ai/rules/tests.md).
 */
function registrationTypeEvent(Brand $brand, string $name = 'SXLan 2027'): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'sxlan-2027',
        name: $name,
        startsAt: '2027-05-14 18:00:00',
        endsAt: '2027-05-16 14:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return $eventId;
}

it('403s the type console without the manage-registration-types capability', function (): void {
    [, $admin] = registrationTypeOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/registration-types')
        ->assertForbidden();
});

it('projects events so the picker offers names, not ULIDs', function (): void {
    // ADR-0004: Registration may not read Event's models.
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);

    expect(EventSummary::withoutBrandScope()->where('event_id', $eventId)->value('name'))
        ->toBe('SXLan 2027');

    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'sxlan-2027',
        name: 'SXLan 2027 — Spring',
    ));

    expect(EventSummary::withoutBrandScope()->count())->toBe(1)
        ->and(EventSummary::withoutBrandScope()->where('event_id', $eventId)->value('name'))
        ->toBe('SXLan 2027 — Spring');
});

it('stops offering an archived event but keeps its name', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    event(new EventArchived(
        eventId: $eventId,
        brandId: $brand->id,
        archivedAt: now()->toIso8601String(),
    ));

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/registration-types')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 0));

    expect(EventSummary::withoutBrandScope()->where('event_id', $eventId)->value('name'))
        ->toBe('SXLan 2027');
});

it('creates an exhibitor type with grants, price and quota links', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $quota = Quota::factory()->create(['name' => 'Booths', 'capacity' => 40]);

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Exhibitor',
            'visibility' => 'public',
            'approval' => 'required',
            'settlement' => 'invoice',
            'confirmationGate' => 'on_approval',
            'termsDays' => 30,
            'grants' => [
                ['kind' => 'admission', 'role' => 'exhibitor', 'quantity' => 4],
            ],
            'priceDeclaration' => [
                'unitAmountNet' => '250.00',
                'taxClass' => 'standard',
                'display' => 'net',
            ],
            'quotaIds' => [$quota->id],
        ])
        ->assertRedirect();

    $type = RegistrationType::withoutBrandScope()->where('name', 'Exhibitor')->sole();

    expect($type->settlement)->toBe(RegistrationSettlement::Invoice)
        ->and($type->confirmation_gate)->toBe(ConfirmationGate::OnApproval)
        ->and($type->terms_days)->toBe(30)
        ->and($type->price_declaration?->unitAmountNet)->toBe('250.00')
        ->and($type->grants->toCollection()->first()->role)->toBe('exhibitor')
        ->and($type->grants->toCollection()->first()->quantity)->toBe(4)
        ->and($type->quotas()->withoutGlobalScope(BrandScope::class)->pluck('quotas.id')->all())
        ->toBe([$quota->id]);
});

it('refuses a priced settlement with no price declaration', function (): void {
    // ADR-0017: RequestSettlement would otherwise throw at submission time,
    // in front of a registrant, for a type saved weeks earlier.
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Broken',
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'invoice',
            'confirmationGate' => 'on_approval',
            'termsDays' => 14,
            'grants' => [],
        ])
        ->assertSessionHasErrors('priceDeclaration');

    expect(RegistrationType::withoutBrandScope()->where('name', 'Broken')->exists())->toBeFalse();
});

it('refuses a free type that carries a price', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Free but priced',
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 0,
            'grants' => [],
            'priceDeclaration' => [
                'unitAmountNet' => '10.00',
                'taxClass' => 'standard',
                'display' => 'gross',
            ],
        ])
        ->assertSessionHasErrors('priceDeclaration');
});

it('refuses an on_payment gate on a settlement nobody pays', function (): void {
    // Nothing would ever confirm such a registration; it would sit in
    // `submitted` with no way for anyone to notice why.
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Never confirms',
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'free',
            'confirmationGate' => 'on_payment',
            'termsDays' => 0,
            'grants' => [],
        ])
        ->assertSessionHasErrors('confirmationGate');
});

it('freezes the terms once a registration exists, but keeps name and quotas editable', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create([
        'event_id' => $eventId,
        'name' => 'Visitor',
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
        'price_declaration' => null,
        'grants' => [],
    ]);
    Registration::factory()->create([
        'registration_type_id' => $type->id,
        'event_id' => $eventId,
    ]);

    // Created before the first admin-host request: that request runs in
    // operator context and leaves no resolved brand behind for a factory.
    $quota = Quota::factory()->create(['name' => 'Late release']);

    $frozen = [
        'eventId' => $eventId,
        'name' => 'Visitor',
        'visibility' => $type->visibility->value,
        'approval' => 'required',
        'settlement' => 'free',
        'confirmationGate' => 'on_submit',
        'termsDays' => $type->terms_days,
        'grants' => [],
    ];

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->id}", $frozen)
        ->assertSessionHasErrors('approval');

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->id}", [
            ...$frozen,
            'approval' => $type->approval->value,
            'name' => 'Visitor (day ticket)',
            'quotaIds' => [$quota->id],
        ])
        ->assertRedirect();

    expect($type->refresh()->name)->toBe('Visitor (day ticket)')
        ->and($type->quotas()->withoutGlobalScope(BrandScope::class)->count())->toBe(1);
});

it('edits everything freely while no one has registered', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create([
        'event_id' => $eventId,
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
        'price_declaration' => null,
    ]);

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->id}", [
            'eventId' => $eventId,
            'name' => 'Now an invoice type',
            'visibility' => 'public',
            'approval' => 'required',
            'settlement' => 'invoice',
            'confirmationGate' => 'on_approval',
            'termsDays' => 21,
            'grants' => [['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1]],
            'priceDeclaration' => [
                'unitAmountNet' => '99.00',
                'taxClass' => 'reduced',
                'display' => 'net',
            ],
        ])
        ->assertRedirect();

    expect($type->refresh()->settlement)->toBe(RegistrationSettlement::Invoice)
        ->and($type->price_declaration?->unitAmountNet)->toBe('99.00');
});

it('refuses a quota that belongs to another brand', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $otherBrandQuota = new Quota(['name' => 'Somebody else', 'capacity' => 10, 'consumed' => 0]);
    $otherBrandQuota->forceFill(['brand_id' => Brand::factory()->create()->id])->save();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Borrowing',
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 0,
            'grants' => [],
            'quotaIds' => [$otherBrandQuota->id],
        ])
        ->assertSessionHasErrors('quotaIds');

    expect(RegistrationType::withoutBrandScope()->where('name', 'Borrowing')->exists())->toBeFalse();
});

it('lists quotas with the types drawing on them', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $quota = Quota::factory()->create(['name' => 'Seats', 'capacity' => 100, 'consumed' => 12]);
    $type = RegistrationType::factory()->create(['event_id' => $eventId, 'name' => 'Visitor']);
    $type->quotas()->attach($quota->id);

    $this->actingAs($admin)
        ->get('http://admin.regtypes.test/admin/quotas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/quotas/Index')
            ->has('quotas.data', 1)
            ->where('quotas.data.0.remaining', 88)
            ->where('quotas.data.0.typeNames', ['Visitor']));
});

it('refuses to cut capacity below the places already taken', function (): void {
    $this->withBrand();
    [, $admin] = registrationTypeOperator();

    $quota = Quota::factory()->create(['name' => 'Seats', 'capacity' => 100, 'consumed' => 40]);

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/quotas/{$quota->id}", [
            'name' => 'Seats',
            'capacity' => 30,
        ])
        ->assertSessionHasErrors('capacity');

    expect($quota->refresh()->capacity)->toBe(100);

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/quotas/{$quota->id}", [
            'name' => 'Seats',
            'capacity' => 120,
        ])
        ->assertRedirect();

    expect($quota->refresh()->capacity)->toBe(120);
});

/**
 * #218 (#216 draft i): the sales window. Both bounds are optional, they are
 * instants (see the migration's docblock), and they deliberately do NOT
 * freeze with the terms — closing a sale early, or extending it by a day, is
 * an operational decision that reinterprets nobody's existing registration.
 */
it('saves a sales window and hands it back to the form as a datetime string', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId]);

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->id}", [
            'eventId' => $eventId,
            'name' => $type->name,
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 14,
            'grants' => [],
            'salesStart' => '2026-10-01 18:00:00',
            'salesEnd' => '2026-10-08 18:00:00',
        ])
        ->assertRedirect();

    expect($type->refresh()->sales_start?->toIso8601String())->toBe('2026-10-01T18:00:00+00:00')
        ->and($type->sales_end?->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');

    $this->actingAs($admin)
        ->get("http://admin.regtypes.test/admin/registration-types/{$type->id}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('type.salesStart', '2026-10-01 18:00:00')
            ->where('type.salesEnd', '2026-10-08 18:00:00'));
});

it('refuses a sales window that ends before it starts', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $this->actingAs($admin)
        ->post('http://admin.regtypes.test/admin/registration-types', [
            'brand_id' => $brand->id,
            'eventId' => $eventId,
            'name' => 'Backwards window',
            'visibility' => 'public',
            'approval' => 'none',
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => 0,
            'grants' => [],
            'salesStart' => '2026-10-08 18:00:00',
            'salesEnd' => '2026-10-01 18:00:00',
        ])
        ->assertSessionHasErrors('salesEnd');

    expect(RegistrationType::withoutBrandScope()->where('name', 'Backwards window')->exists())->toBeFalse();
});

it('keeps the sales window editable once registrations exist', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create([
        'event_id' => $eventId,
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
        'price_declaration' => null,
        'grants' => [],
    ]);
    Registration::factory()->create([
        'registration_type_id' => $type->id,
        'event_id' => $eventId,
    ]);

    $this->actingAs($admin)
        ->put("http://admin.regtypes.test/admin/registration-types/{$type->id}", [
            'eventId' => $eventId,
            'name' => $type->name,
            'visibility' => $type->visibility->value,
            'approval' => $type->approval->value,
            'settlement' => 'free',
            'confirmationGate' => 'on_submit',
            'termsDays' => $type->terms_days,
            'grants' => [],
            'salesEnd' => '2026-10-08 18:00:00',
        ])
        ->assertRedirect();

    expect($type->refresh()->sales_end?->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');
});

/**
 * Fix round 1, finding 1: the payload the Vue form actually sends. `Edit.vue`
 * transforms on every submit and therefore always sends both keys, `null`
 * when the input is empty — so a `rules()` entry that drops the inferred
 * `nullable` breaks saving *any* type, not just a windowed one.
 *
 * @param  array<string, mixed>  $window
 */
function registrationTypeWindowPayload(string $eventId, array $window): array
{
    return [
        'eventId' => $eventId,
        'name' => 'Windowed',
        'visibility' => 'public',
        'approval' => 'none',
        'settlement' => 'free',
        'confirmationGate' => 'on_submit',
        'termsDays' => 14,
        'grants' => [],
        'quotaIds' => [],
        ...$window,
    ];
}

it('saves the form payload the admin page sends for every shape of window', function (array $window, ?string $expectedStart, ?string $expectedEnd): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create([
        'event_id' => $eventId,
        'sales_start' => '2026-01-01 00:00:00',
        'sales_end' => '2026-12-31 00:00:00',
    ]);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            registrationTypeWindowPayload($eventId, $window),
        )
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($type->refresh()->sales_start?->toIso8601String())->toBe($expectedStart)
        ->and($type->sales_end?->toIso8601String())->toBe($expectedEnd);
})->with([
    'both null (an unwindowed type, and the way a window is cleared)' => [
        ['salesStart' => null, 'salesEnd' => null], null, null,
    ],
    'start only (the brief\'s own headline case: "sale starts <date>")' => [
        ['salesStart' => '2026-10-01 18:00:00', 'salesEnd' => null],
        '2026-10-01T18:00:00+00:00',
        null,
    ],
    'end only' => [
        ['salesStart' => null, 'salesEnd' => '2026-10-08 18:00:00'],
        null,
        '2026-10-08T18:00:00+00:00',
    ],
    'both set' => [
        ['salesStart' => '2026-10-01 18:00:00', 'salesEnd' => '2026-10-08 18:00:00'],
        '2026-10-01T18:00:00+00:00',
        '2026-10-08T18:00:00+00:00',
    ],
]);

it('still refuses an end before the start when the form sends both keys', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId]);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            registrationTypeWindowPayload($eventId, [
                'salesStart' => '2026-10-08 18:00:00',
                'salesEnd' => '2026-10-01 18:00:00',
            ]),
        )
        ->assertSessionHasErrors('salesEnd');

    expect($type->refresh()->sales_start)->toBeNull();
});

it('refuses a sales window that is not a date at all', function (): void {
    $brand = $this->withBrand();
    $eventId = registrationTypeEvent($brand);
    [, $admin] = registrationTypeOperator();

    $type = RegistrationType::factory()->create(['event_id' => $eventId]);

    $this->actingAs($admin)
        ->put(
            "http://admin.regtypes.test/admin/registration-types/{$type->id}",
            registrationTypeWindowPayload($eventId, ['salesStart' => 'whenever', 'salesEnd' => null]),
        )
        ->assertSessionHasErrors('salesStart');
});
