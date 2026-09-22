<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Identity\Models\User;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run — a function declared inside a *Test.php
 * file exists only in the process that happened to load that file, and the
 * names stay process-global across all modules (.ai/rules/tests.md).
 */
function operatorWithAdmin(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.op.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', 'platform.view-brands', 'platform.manage-brands'] as $name) {
        PermissionModel::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo(PermissionModel::query()->pluck('name')->all());

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * The theme editor's operator (ThemeAdminTest, ThemeImportExportTest):
 * mirrors operatorWithAdmin() under its own admin host and brand, and
 * grants exactly the capabilities each test names so a 403 test proves
 * platform.access-admin alone is not enough.
 *
 * @param  array<int, string>  $capabilities
 * @return array{0: Operator, 1: User, 2: Brand}
 */
function operatorWithThemeAdmin(array $capabilities = ['platform.access-admin', 'platform.manage-brand-theme']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.theme.test'])->save();

    $brand = Brand::factory()->withDomain('theme-brand.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->create([
        'name' => 'Theme Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin, $brand];
}

/**
 * The Admission event that opens the live hub for a person (design §5.1):
 * what `ActiveEventContextTest` and `ActiveEventSharedPropTest` feed the
 * `platform_hub_access` projection with. Display fields are fixed so the
 * assertions have something to compare; only the ids and the instant vary.
 */
function hubAdmitted(string $brandId, string $userId, string $eventId, string $admittedAt = '2026-10-16T18:00:00Z'): ParticipantAdmitted
{
    return new ParticipantAdmitted(
        userId: $userId,
        eventId: $eventId,
        brandId: $brandId,
        admissionId: (string) Str::ulid(),
        admittedAt: $admittedAt,
        windowEndsAt: null,
        dayLabel: 'Freitag',
        eventName: 'Kon Fest',
        eventSlug: 'kon-fest',
    );
}

/**
 * The `ActiveEventData` a hub card's payload closure is handed (design
 * §5.2), built directly instead of through `ActiveEventContext`: a card
 * test is about what the *card* makes of the running event, not about how
 * that event was resolved — `ActiveEventContextTest` already owns the
 * resolution. Display fields are fixed so an assertion has something to
 * compare; only the ids vary.
 *
 * Here rather than in a test file because Admission's and Content's card
 * tests both call it, and a function declared in a *Test.php exists only
 * in the worker that loaded it (.ai/rules/tests.md).
 *
 * `$brand` and `$user` name the pair the hub was resolved for — neither is
 * a field of `ActiveEventData`, they are in the signature so the call site
 * reads like the real one does.
 */
function hubActiveEventFor(Brand $brand, User $user, string $eventId, string $admissionId): ActiveEventData
{
    return new ActiveEventData(
        eventId: $eventId,
        eventSlug: 'kon-fest',
        eventName: 'Kon Fest',
        admissionId: $admissionId,
        dayLabel: 'Freitag',
        admittedAt: '2026-10-16T18:00:00+00:00',
        windowEndsAt: null,
        onSite: true,
    );
}

/**
 * An empty `HubRegistry`, bound in place of the application's for the rest
 * of the test.
 *
 * `HubPageTest` and `HubReplacesHomeTest` are about the *page* — the
 * operator's order, the payload a hidden card never runs, which component
 * `/` renders — not about what any module happens to contribute. Since
 * D-HUB-05 four modules register real cards and links at boot, and one of
 * them resolves the member's actual admission: a synthetic `hubAdmitted()`
 * row names an admission that was never issued, and rendering every
 * registered card against it says nothing about the page.
 *
 * So those tests render against a registry they own and fill themselves.
 * What the four launch contributions register is `HubLaunchContributionsTest`'s
 * subject, and what each one builds is its own module's.
 */
function hubIsolatedRegistry(): HubRegistry
{
    $registry = new HubRegistry(app(ModuleGate::class));

    app()->instance(HubRegistry::class, $registry);

    return $registry;
}
