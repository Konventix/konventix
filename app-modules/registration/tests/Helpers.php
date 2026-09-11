<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run — a function declared inside a *Test.php
 * file exists only in the process that happened to load that file, and the
 * names stay process-global across all modules (.ai/rules/tests.md).
 */
function emptySubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

function regMailsRender(string $templateKey, RegistrationMailData $payload, string $locale = 'de'): string
{
    $message = new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        recipientId: (string) Str::ulid(),
        recipientEmail: 'registrant@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: $payload,
        channel: Channel::Mail->value,
    );

    return app(TemplateRenderer::class)->render($message)->body;
}

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
