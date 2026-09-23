<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\OperatorSettings;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator's own settings page (ADR-0029, ADR-0030). The legal seller
 * identity every invoice is issued under lived only in a seeder row until now:
 * `OperatorSettings::sellerForBrand()` reads it, Commerce prints it on a
 * ZUGFeRD document, and nobody could correct a typo in it without tinker.
 *
 * The operator being edited is always the one resolved from the admin host —
 * never an id from the request. There is one operator per host by
 * construction, and accepting a route parameter would invent a way to edit
 * another one.
 *
 * @return array{0: Operator, 1: User}
 */
function operatorWithSettingsAdmin(array $capabilities = ['platform.access-admin', 'platform.manage-operator']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.operatorsettings.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Settings Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('carries the operator-wide date and time format the console sells under', function (): void {
    // Operator level, not brand level (#242): one instance sells under one
    // set of conventions, and an invoice, a mail and a console table that
    // disagreed about which one would each be defensible on their own.
    [$operator, $admin] = operatorWithSettingsAdmin();
    $operator->forceFill(['date_format' => 'MM/DD/YYYY', 'time_format' => '12h'])->save();

    $this->actingAs($admin)
        ->get('http://admin.operatorsettings.test/admin/settings/operator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('operator.dateFormat', 'MM/DD/YYYY')
            ->where('operator.timeFormat', '12h'));
});

it('saves a changed date and time format', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'contactEmail' => null,
            'addressLine' => null,
            'postalCode' => null,
            'city' => null,
            'countryCode' => 'DE',
            'vatId' => null,
            'taxNumber' => null,
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'YYYY-MM-DD',
            'timeFormat' => '12h',
            'locale' => 'en',
        ])
        ->assertRedirect();

    $operator->refresh();

    expect($operator->date_format)->toBe('YYYY-MM-DD')
        ->and($operator->time_format)->toBe('12h');
});

it('saves the operator closing signups', function (): void {
    // Hotfix 2026-09-13: the switch Identity's `EnsureSignupsOpen` reads
    // through `OperatorSettings::signupsOpen()`.
    [$operator, $admin] = operatorWithSettingsAdmin();

    expect($operator->refresh()->signups_open)->toBeTrue();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'countryCode' => 'DE',
            'smallBusinessScheme' => false,
            'signupsOpen' => false,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertRedirect();

    expect($operator->refresh()->signups_open)->toBeFalse()
        ->and(app(OperatorSettings::class)->signupsOpen())->toBeFalse();
});

it('tells the operator when the environment has closed signups over their head', function (): void {
    [, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->get('http://admin.operatorsettings.test/admin/settings/operator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('operator.signupsOpen', true)
            ->where('signupsForcedClosed', false));

    config()->set('platform.signups.enabled', false);

    $this->actingAs($admin)
        ->get('http://admin.operatorsettings.test/admin/settings/operator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('operator.signupsOpen', true)
            ->where('signupsForcedClosed', true));

    // The cap is the environment's alone: the row still says open, and the
    // combined answer still says closed.
    expect(app(OperatorSettings::class)->signupsOpen())->toBeFalse();
});

it('refuses a date format nobody ships a formatter for', function (): void {
    [, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'countryCode' => 'DE',
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'the fourteenth',
            'timeFormat' => 'sundial',
            'locale' => 'en',
        ])
        ->assertInvalid(['dateFormat', 'timeFormat']);
});

it('shows the operator resolved from the admin host', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();
    $operator->forceFill(['legal_name' => 'Sund-Xplosion e.V.', 'city' => 'Flensburg'])->save();

    $this->actingAs($admin)
        ->get('http://admin.operatorsettings.test/admin/settings/operator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/settings/Operator')
            ->where('operator.legalName', 'Sund-Xplosion e.V.')
            ->where('operator.city', 'Flensburg'));
});

it('saves the legal seller identity', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'contactEmail' => 'vorstand@sxlan.test',
            'addressLine' => 'Hafendamm 1',
            'postalCode' => '24937',
            'city' => 'Flensburg',
            'countryCode' => 'DE',
            'vatId' => 'DE123456789',
            'taxNumber' => '15/123/45678',
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertRedirect();

    $operator->refresh();

    expect($operator->legal_name)->toBe('Sund-Xplosion e.V.')
        ->and($operator->city)->toBe('Flensburg')
        ->and($operator->vat_id)->toBe('DE123456789')
        ->and($operator->small_business_scheme)->toBeFalse();
});

/**
 * ADR-0030: the §19 election is operator-level by law, and it changes what
 * every future invoice may say about VAT. It is a checkbox that matters.
 */
it('records the small-business election', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'countryCode' => 'DE',
            'smallBusinessScheme' => true,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertRedirect();

    expect($operator->refresh()->small_business_scheme)->toBeTrue();
});

it('requires a legal name, because an invoice cannot be issued without one', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();
    $before = $operator->legal_name;

    $this->actingAs($admin)
        ->from('http://admin.operatorsettings.test/admin/settings/operator')
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => '',
            'countryCode' => 'DE',
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertSessionHasErrors('legalName');

    expect($operator->refresh()->legal_name)->toBe($before);
});

it('rejects a country code that is not two letters', function (): void {
    [, $admin] = operatorWithSettingsAdmin();

    $this->actingAs($admin)
        ->from('http://admin.operatorsettings.test/admin/settings/operator')
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Sund-Xplosion e.V.',
            'countryCode' => 'Germany',
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertSessionHasErrors('countryCode');
});

it('refuses an admin holding console access but not the operator capability', function (): void {
    [, $admin] = operatorWithSettingsAdmin(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.operatorsettings.test/admin/settings/operator')
        ->assertForbidden();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Hijacked e.V.',
            'countryCode' => 'DE',
            'smallBusinessScheme' => false,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ])
        ->assertForbidden();
});

/**
 * The seller identity Commerce reads must be the one just saved — that is the
 * entire point of the page, and the only proof that editing it changes what an
 * invoice will say.
 */
it('changes what Commerce reads as the invoice seller', function (): void {
    [$operator, $admin] = operatorWithSettingsAdmin();

    $brand = Brand::factory()->create();
    $brand->operator()->associate($operator)->save();

    $this->actingAs($admin)
        ->put('http://admin.operatorsettings.test/admin/settings/operator', [
            'name' => 'Konventix',
            'legalName' => 'Renamed e.V.',
            'countryCode' => 'DE',
            'smallBusinessScheme' => true,
            'signupsOpen' => true,
            'dateFormat' => 'DD.MM.YYYY',
            'timeFormat' => '24h',
            'locale' => 'en',
        ]);

    $seller = app(OperatorSettings::class)
        ->sellerForBrand((string) $brand->getKey());

    expect($seller->legalName)->toBe('Renamed e.V.')
        ->and($seller->smallBusinessScheme)->toBeTrue();
});
