<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
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
