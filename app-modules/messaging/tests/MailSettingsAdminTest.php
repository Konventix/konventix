<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator console's mail-settings screen (ADR-0028): sender identity,
 * quiet-hours timezone (ADR-0041) and SMTP transport per brand. The stored
 * password never travels to the browser and survives a blank re-submit.
 */
function mailSettingsOperator(array $permissions = [
    'platform.access-admin',
    'messaging.manage-sender-identity',
]): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.mailset.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Mail Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $admin;
}

it('lists every brand with its transport state', function (): void {
    $admin = mailSettingsOperator();

    $configured = Brand::factory()->create(['name' => 'Configured', 'slug' => 'ms-configured']);
    Brand::factory()->create(['name' => 'Bare', 'slug' => 'ms-bare']);

    BrandMailSettings::factory()
        ->withTransport(['transport' => 'smtp', 'host' => 'mail.test', 'port' => 587, 'password' => 'secret'])
        ->create(['brand_id' => $configured->getKey(), 'from_address' => 'hi@configured.test']);

    $this->actingAs($admin)
        ->get('http://admin.mailset.test/admin/mail-settings')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            // Not an exact count: the operator factory seeds a brand of
            // its own — the two named here are what this test owns.
            $page->component('admin/mail-settings/Index')->has('brands');

            $brands = collect($page->toArray()['props']['brands'])->keyBy('name');
            expect($brands['Configured']['customTransport'])->toBeTrue()
                ->and($brands['Configured']['fromAddress'])->toBe('hi@configured.test')
                ->and($brands['Bare']['customTransport'])->toBeFalse();
        });
});

it('shows settings on the edit page without leaking the SMTP password', function (): void {
    $admin = mailSettingsOperator();

    $brand = Brand::factory()->create(['slug' => 'ms-edit']);
    BrandMailSettings::factory()
        ->withTransport(['transport' => 'smtp', 'host' => 'mail.test', 'port' => 587, 'username' => 'mailer', 'password' => 'super-secret'])
        ->create(['brand_id' => $brand->getKey()]);

    $response = $this->actingAs($admin)
        ->get("http://admin.mailset.test/admin/mail-settings/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/mail-settings/Edit')
            ->where('settings.smtpHost', 'mail.test')
            ->where('settings.hasSmtpPassword', true));

    expect(json_encode($response->viewData('page')))->not->toContain('super-secret');
});

it('creates the settings row for a brand that had none', function (): void {
    $admin = mailSettingsOperator();

    $brand = Brand::factory()->create(['slug' => 'ms-create']);

    $this->actingAs($admin)
        ->put("http://admin.mailset.test/admin/mail-settings/{$brand->getKey()}", [
            'fromName' => 'SXLan Crew',
            'fromAddress' => 'crew@sxlan.test',
            'replyTo' => null,
            'timezone' => 'Europe/Berlin',
            'useCustomTransport' => false,
        ])
        ->assertRedirect();

    $settings = BrandMailSettings::withoutBrandScope()->where('brand_id', $brand->getKey())->sole();
    expect($settings->from_name)->toBe('SXLan Crew')
        ->and($settings->timezone)->toBe('Europe/Berlin')
        ->and($settings->transport)->toBeNull();
});

it('keeps the stored password when the form submits none, drops transport when unchecked', function (): void {
    $admin = mailSettingsOperator();

    $brand = Brand::factory()->create(['slug' => 'ms-keep']);
    BrandMailSettings::factory()
        ->withTransport(['transport' => 'smtp', 'host' => 'mail.test', 'port' => 587, 'username' => 'mailer', 'password' => 'keep-me', 'scheme' => null])
        ->create(['brand_id' => $brand->getKey(), 'from_name' => 'Old', 'from_address' => 'old@test.test']);

    $payload = [
        'fromName' => 'New Name',
        'fromAddress' => 'new@test.test',
        'replyTo' => null,
        'timezone' => null,
        'useCustomTransport' => true,
        'smtpHost' => 'mail2.test',
        'smtpPort' => 465,
        'smtpUsername' => 'mailer2',
        'smtpPassword' => null,
        'smtpScheme' => 'smtps',
    ];

    $this->actingAs($admin)
        ->put("http://admin.mailset.test/admin/mail-settings/{$brand->getKey()}", $payload)
        ->assertRedirect();

    $settings = BrandMailSettings::withoutBrandScope()->where('brand_id', $brand->getKey())->sole();
    expect($settings->transport['password'])->toBe('keep-me')
        ->and($settings->transport['host'])->toBe('mail2.test')
        ->and($settings->transport['scheme'])->toBe('smtps');

    // Unchecking the transport drops it entirely — back to the default mailer.
    $this->actingAs($admin)
        ->put("http://admin.mailset.test/admin/mail-settings/{$brand->getKey()}", [
            ...$payload,
            'useCustomTransport' => false,
        ])
        ->assertRedirect();

    expect($settings->refresh()->transport)->toBeNull();
});

it('requires the sender-identity capability', function (): void {
    $admin = mailSettingsOperator(permissions: ['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.mailset.test/admin/mail-settings')
        ->assertForbidden();
});
