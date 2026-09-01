<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: the data subject request queue in the operator console, and the
 * three-way capability split that makes reading the queue, acting on it and
 * reading an export's contents different levels of access.
 *
 * Own helper rather than PolicyAdminTest's: Pest helpers are process-global,
 * so a shared one would tie this file's ability to run alone to another
 * file's load order (.ai/rules/tests.md).
 */
function complianceRequestOperator(array $permissions = [
    'platform.access-admin',
    'compliance.view-subject-requests',
    'compliance.handle-subject-requests',
    'compliance.download-subject-export',
], string $host = 'admin.dsr.test'): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

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

it('403s the request queue without the view capability', function (): void {
    [, $admin] = complianceRequestOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.dsr.test/admin/subject-requests')
        ->assertForbidden();
});

it('lists requests with days left and the number of modules still open', function (): void {
    [, $admin] = complianceRequestOperator();
    $this->withBrand();

    $request = DataSubjectRequest::factory()->create([
        'type' => DataSubjectRequestType::Export,
        'state' => DataSubjectRequestState::InProgress,
        'deadline_at' => now()->addDays(3),
    ]);

    DataSubjectRequestResponse::factory()->create([
        'data_subject_request_id' => $request->id,
        'module' => 'registration',
        'state' => ModuleResponseState::Failed,
    ]);
    DataSubjectRequestResponse::factory()->create([
        'data_subject_request_id' => $request->id,
        'module' => 'commerce',
        'state' => ModuleResponseState::Completed,
    ]);

    $this->actingAs($admin)
        ->get('http://admin.dsr.test/admin/subject-requests?state=in_progress')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/subject-requests/Index')
            ->has('requests.data', 1)
            ->where('requests.data.0.daysLeft', 3)
            ->where('requests.data.0.openModules', 1));
});

it('shows per-module responses and names modules the fan-out never addressed', function (): void {
    [, $admin] = complianceRequestOperator();
    $this->withBrand();

    $request = DataSubjectRequest::factory()->create(['type' => DataSubjectRequestType::Export]);
    DataSubjectRequestResponse::factory()->create([
        'data_subject_request_id' => $request->id,
        'module' => 'registration',
        'state' => ModuleResponseState::Completed,
    ]);

    $this->actingAs($admin)
        ->get("http://admin.dsr.test/admin/subject-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/subject-requests/Show')
            ->has('responses', 1)
            ->where('responses.0.module', 'registration')
            // Every other module that registered a handler after this
            // request opened shows as never addressed — which is what
            // re-dispatch fixes.
            ->where('missingModules', fn (Collection $missing): bool => ! $missing->contains('registration')
                && $missing->contains('identity'))
            ->where('canHandle', true)
            ->where('canDownload', true));
});

it('hides the handling actions from an operator who may only read the queue', function (): void {
    [, $admin] = complianceRequestOperator(['platform.access-admin', 'compliance.view-subject-requests']);
    $this->withBrand();

    $request = DataSubjectRequest::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.dsr.test/admin/subject-requests/{$request->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canHandle', false)
            ->where('canDownload', false));

    $this->actingAs($admin)
        ->post("http://admin.dsr.test/admin/subject-requests/{$request->id}/refuse", [
            'reasonCode' => 'art_12_5',
        ])
        ->assertForbidden();
});

it('refuses a request with a legal ground and emits SubjectRequestRefused', function (): void {
    [, $admin] = complianceRequestOperator();
    $brand = $this->withBrand();

    $request = DataSubjectRequest::factory()->create([
        'filed_on_brand_id' => $brand->getKey(),
        'type' => DataSubjectRequestType::Deletion,
        'mode' => DeletionMode::Anonymize,
    ]);

    Event::fake([SubjectRequestRefused::class]);

    $this->actingAs($admin)
        ->post("http://admin.dsr.test/admin/subject-requests/{$request->id}/refuse", [
            'reasonCode' => 'art_17_3_b_legal_obligation',
        ])
        ->assertRedirect();

    expect($request->refresh()->state)->toBe(DataSubjectRequestState::Refused)
        ->and($request->reason_code)->toBe('art_17_3_b_legal_obligation')
        ->and($request->closed_at)->not->toBeNull();

    Event::assertDispatched(
        SubjectRequestRefused::class,
        fn (SubjectRequestRefused $event): bool => $event->requestId === $request->id
            && $event->brandId === $brand->id
            && $event->reasonCode === 'art_17_3_b_legal_obligation',
    );
});

it('streams the archive only to an operator holding the download capability', function (): void {
    Storage::fake('exports');
    $this->withBrand();

    $request = DataSubjectRequest::factory()->create([
        'archive_path' => 'archive.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->addDays(5),
        'state' => DataSubjectRequestState::Fulfilled,
    ]);

    Storage::disk('exports')->put('archive.json', '{"modules":{}}');

    // Two operators, so two admin hosts — `operators.admin_host` is unique.
    [, $reader] = complianceRequestOperator(
        ['platform.access-admin', 'compliance.view-subject-requests', 'compliance.handle-subject-requests'],
        host: 'admin.dsr-reader.test',
    );

    $this->actingAs($reader)
        ->get("http://admin.dsr-reader.test/admin/subject-requests/{$request->id}/download")
        ->assertForbidden();

    [, $downloader] = complianceRequestOperator();

    $this->actingAs($downloader)
        ->get("http://admin.dsr.test/admin/subject-requests/{$request->id}/download")
        ->assertOk();
});

it('404s a download whose archive has expired', function (): void {
    Storage::fake('exports');
    [, $admin] = complianceRequestOperator();
    $this->withBrand();

    $request = DataSubjectRequest::factory()->create([
        'archive_path' => 'archive.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->get("http://admin.dsr.test/admin/subject-requests/{$request->id}/download")
        ->assertNotFound();
});

it('opens one self-service request per type and refuses a second while it is open', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    Storage::fake('exports');

    // Without this the sync queue would carry the first request all the way
    // to fulfilled before the second arrives, and there would be nothing open
    // for the guard to catch.
    Queue::fake();

    $this->actingAs($user)
        ->post('http://localhost/data-requests', ['type' => 'deletion'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post('http://localhost/data-requests', ['type' => 'deletion'])
        ->assertRedirect();

    expect(DataSubjectRequest::query()->where('subject_id', $user->id)->count())->toBe(1);

    $request = DataSubjectRequest::query()->firstOrFail();

    // Self-service deletion is always anonymize-with-tombstone; erasure is an
    // operator decision, not a button.
    expect($request->mode)->toBe(DeletionMode::Anonymize);
});

it('lets a subject download their own archive and nobody else theirs', function (): void {
    Storage::fake('exports');
    $this->withBrand();

    $user = User::factory()->create();
    $other = User::factory()->create();

    $request = DataSubjectRequest::factory()->create([
        'subject_id' => $user->id,
        'archive_path' => 'mine.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->addDays(3),
        'state' => DataSubjectRequestState::Fulfilled,
    ]);

    Storage::disk('exports')->put('mine.json', '{"modules":{}}');

    $this->actingAs($user)
        ->get("http://localhost/data-requests/{$request->id}/download")
        ->assertOk();

    $this->actingAs($other)
        ->get("http://localhost/data-requests/{$request->id}/download")
        ->assertForbidden();
});

it('shows a subject their own requests on the privacy page', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    DataSubjectRequest::factory()->create([
        'subject_id' => $user->id,
        'type' => DataSubjectRequestType::Export,
        'state' => DataSubjectRequestState::Received,
    ]);
    DataSubjectRequest::factory()->create(['subject_id' => (string) Str::ulid()]);

    $this->actingAs($user)
        ->get('http://localhost/settings/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('requests', 1)
            ->where('requests.0.type', 'export')
            ->where('openTypes', ['export']));
});
