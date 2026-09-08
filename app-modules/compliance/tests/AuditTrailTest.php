<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Actions\RefuseSubjectRequest;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Spatie\Permission\PermissionRegistrar;

/**
 * The hybrid audit trail (Round 6 D-BATCH): a wildcard subscriber over events
 * that declare themselves auditable, plus the explicit recorder for actions
 * with no event to subscribe to.
 *
 * Own operator helper — Pest helpers are process-global (.ai/rules/tests.md).
 */
function complianceAuditOperator(array $permissions = [
    'platform.access-admin',
    'compliance.view-audit-log',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.audit.test'])->save();

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

it('records an auditable event of this module without anyone calling the recorder', function (): void {
    $brand = $this->withBrand();

    $version = PolicyVersion::factory()
        ->for(Policy::factory()->create(['slug' => 'terms']))
        ->create(['effective_at' => now()->subMinute()]);

    (new PublishPolicyVersion)($version);

    $entry = AuditLogEntry::query()->where('action', 'compliance.policy.published')->firstOrFail();

    expect($entry->subject_id)->toBe($version->id)
        ->and($entry->brand_id)->toBe($brand->id)
        ->and($entry->context['slug'])->toBe('terms');
});

it('records another module event that declares itself auditable', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create(['approval' => RegistrationApproval::Required]);
    $registration = Registration::factory()->for($type, 'registrationType')->create();
    $reviewer = (string) Str::ulid();

    app(ApproveRegistration::class)->handle($registration, $reviewer);

    $entry = AuditLogEntry::query()->where('action', 'registration.approved')->firstOrFail();

    // The event carries its own actor — Registration knows who approved,
    // and the trail must not fall back to whoever happens to be logged in.
    expect($entry->actor_user_id)->toBe($reviewer)
        ->and($entry->subject_id)->toBe($registration->id);
});

it('ignores the ocean of events that are not auditable', function (): void {
    $this->withBrand();

    // Model events, framework internals and every unmarked domain event pass
    // through the same wildcard listener.
    Policy::factory()->count(3)->create();

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('records an eventless action through the explicit recorder, attributing the logged-in actor', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user);

    app(AuditRecorder::class)->record(
        action: 'registration.registrations.exported',
        brandId: $brand->id,
        context: ['rows' => 12],
    );

    $entry = AuditLogEntry::query()->where('action', 'registration.registrations.exported')->firstOrFail();

    expect($entry->actor_user_id)->toBe($user->id)
        ->and($entry->context['rows'])->toBe(12)
        ->and($entry->occurred_at)->not->toBeNull();
});

it('records a refusal with its legal ground', function (): void {
    $this->withBrand();
    Storage::fake('exports');

    $request = DataSubjectRequest::factory()->create(['type' => DataSubjectRequestType::Rectification]);

    (new RefuseSubjectRequest)($request, 'art_12_5_excessive');

    $entry = AuditLogEntry::query()->where('action', 'compliance.subject-request.refused')->firstOrFail();

    expect($entry->context['reason_code'])->toBe('art_12_5_excessive')
        ->and($entry->subject_id)->toBe($request->subject_id);
});

it('403s the audit console without the view-audit-log capability', function (): void {
    [, $admin] = complianceAuditOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.audit.test/admin/audit')
        ->assertForbidden();
});

it('lists entries newest first and filters by action', function (): void {
    [, $admin] = complianceAuditOperator();
    $this->withBrand();

    AuditLogEntry::factory()->create([
        'action' => 'compliance.policy.published',
        'occurred_at' => now()->subDay(),
    ]);
    AuditLogEntry::factory()->create([
        'action' => 'registration.registrations.exported',
        'occurred_at' => now(),
        'brand_id' => null,
    ]);

    $this->actingAs($admin)
        ->get('http://admin.audit.test/admin/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/audit/Index')
            ->has('entries.data', 2)
            ->where('entries.data.0.action', 'registration.registrations.exported')
            // No brand means the operator host, not a missing value.
            ->where('entries.data.0.brandName', null)
            // An ISO instant, not a preformatted UTC string (#238 fix round
            // 1, review finding 6): the page renders it in the reader's own
            // zone through `formatDateTime()`, like every other admin list.
            ->where('entries.data.0.occurredAt', fn (string $at): bool => (bool) preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $at,
            ))
            ->has('actions', 2));

    $this->actingAs($admin)
        ->get('http://admin.audit.test/admin/audit?action=compliance.policy.published')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});
