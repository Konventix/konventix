<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Listeners\PruneAuditTrail;
use Konventix\Compliance\Listeners\StartRetentionClock;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\RetentionClock;
use Konventix\Compliance\Models\RetentionRule;
use Konventix\Event\Events\EventConcluded;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Listeners\PruneDeliveryLog;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retention: Compliance publishes the rule and emits when a period runs out;
 * the module that holds the data deletes its own rows (SPEC "Owns").
 *
 * Own operator helper — Pest helpers are process-global (.ai/rules/tests.md).
 */
function complianceRetentionOperator(array $permissions = [
    'platform.access-admin',
    'compliance.manage-retention-rules',
], string $host = 'admin.retention.test'): array
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

it('ships the two D-CPL-01 numbers as operator-wide defaults', function (): void {
    $rules = RetentionRule::query()->whereNull('brand_id')->pluck('retention_months', 'data_category');

    expect($rules['compliance.audit-trail'])->toBe(36)
        ->and($rules['messaging.delivery-log'])->toBe(12);
});

it('emits one signal per active record-age rule, carrying the cutoff', function (): void {
    $this->withBrand();
    Storage::fake('exports');

    RetentionRule::query()->where('data_category', 'messaging.delivery-log')->update(['active' => false]);

    Event::fake([RetentionPeriodElapsed::class]);

    $this->artisan('compliance:apply-retention')->assertSuccessful();

    Event::assertDispatchedTimes(RetentionPeriodElapsed::class, 1);
    Event::assertDispatched(
        RetentionPeriodElapsed::class,
        fn (RetentionPeriodElapsed $event): bool => $event->dataCategory === 'compliance.audit-trail'
            && $event->eventId === null
            && now()->parse($event->cutoffAt)->isSameDay(now()->subMonths(36)),
    );
});

it('starts a clock when an event concludes, idempotently', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $concluded = new EventConcluded(
        eventId: $eventId,
        brandId: $brand->id,
        concludedAt: now()->subYears(2)->toIso8601String(),
    );

    (new StartRetentionClock)->handle($concluded);
    (new StartRetentionClock)->handle($concluded);

    expect(RetentionClock::query()->where('event_id', $eventId)->count())->toBe(1);
});

it('emits one signal per concluded event for an event-scoped rule', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    RetentionRule::query()->update(['active' => false]);
    $rule = RetentionRule::factory()->afterEvent()->create([
        'data_category' => 'admission.check-ins',
        'retention_months' => 12,
    ]);

    RetentionClock::factory()->create([
        'brand_id' => $brand->id,
        'concluded_at' => now()->subMonths(18),
    ]);
    // Concluded last month — nowhere near out of retention.
    RetentionClock::factory()->create([
        'brand_id' => $brand->id,
        'concluded_at' => now()->subMonth(),
    ]);

    Event::fake([RetentionPeriodElapsed::class]);

    $this->artisan('compliance:apply-retention')->assertSuccessful();

    Event::assertDispatchedTimes(RetentionPeriodElapsed::class, 1);
    Event::assertDispatched(
        RetentionPeriodElapsed::class,
        fn (RetentionPeriodElapsed $event): bool => $event->ruleId === $rule->id
            && $event->eventId !== null
            && $event->brandId === $brand->id,
    );
});

it('prunes the audit trail by the cutoff, not wholesale', function (): void {
    $this->withBrand();

    $old = AuditLogEntry::factory()->create(['occurred_at' => now()->subYears(4)]);
    $recent = AuditLogEntry::factory()->create(['occurred_at' => now()->subMonth()]);

    (new PruneAuditTrail)->handle(new RetentionPeriodElapsed(
        ruleId: (string) Str::ulid(),
        brandId: null,
        dataCategory: 'compliance.audit-trail',
        cutoffAt: now()->subMonths(36)->toIso8601String(),
    ));

    expect(AuditLogEntry::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('ignores a category it does not own', function (): void {
    $this->withBrand();

    AuditLogEntry::factory()->create(['occurred_at' => now()->subYears(4)]);

    (new PruneAuditTrail)->handle(new RetentionPeriodElapsed(
        ruleId: (string) Str::ulid(),
        brandId: null,
        dataCategory: 'messaging.delivery-log',
        cutoffAt: now()->toIso8601String(),
    ));

    expect(AuditLogEntry::query()->count())->toBe(1);
});

it('lets Messaging prune its own delivery log, leaving messages still in flight', function (): void {
    $brand = $this->withBrand();

    $sent = MessageRecord::factory()->create([
        'queued_at' => now()->subYears(2),
        'sent_at' => now()->subYears(2),
        'status' => MessageStatus::Sent,
    ]);
    $stillQueued = MessageRecord::factory()->create([
        'queued_at' => now()->subYears(2),
        'sent_at' => null,
        'status' => MessageStatus::Queued,
    ]);

    (new PruneDeliveryLog)->handle(new RetentionPeriodElapsed(
        ruleId: (string) Str::ulid(),
        brandId: $brand->id,
        dataCategory: 'messaging.delivery-log',
        cutoffAt: now()->subMonths(12)->toIso8601String(),
    ));

    expect(MessageRecord::withoutBrandScope()->whereKey($sent->id)->exists())->toBeFalse()
        ->and(MessageRecord::withoutBrandScope()->whereKey($stillQueued->id)->exists())->toBeTrue();
});

it('deletes expired export archives and clears their pointer', function (): void {
    $this->withBrand();
    Storage::fake('exports');
    Storage::disk('exports')->put('old.json', '{}');
    Storage::disk('exports')->put('fresh.json', '{}');

    $expired = DataSubjectRequest::factory()->create([
        'type' => DataSubjectRequestType::Export,
        'archive_path' => 'old.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->subDay(),
    ]);
    $live = DataSubjectRequest::factory()->create([
        'type' => DataSubjectRequestType::Export,
        'archive_path' => 'fresh.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->addDays(5),
    ]);

    $this->artisan('compliance:apply-retention')->assertSuccessful();

    Storage::disk('exports')->assertMissing('old.json');
    Storage::disk('exports')->assertExists('fresh.json');

    // The request itself survives — the record that an export happened is not
    // the export.
    expect($expired->refresh()->archive_path)->toBeNull()
        ->and($live->refresh()->archive_path)->toBe('fresh.json');
});

it('changes nothing on a dry run', function (): void {
    $this->withBrand();
    Storage::fake('exports');
    Storage::disk('exports')->put('old.json', '{}');

    DataSubjectRequest::factory()->create([
        'archive_path' => 'old.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->subDay(),
    ]);

    Event::fake([RetentionPeriodElapsed::class]);

    $this->artisan('compliance:apply-retention --dry-run')->assertSuccessful();

    Event::assertNotDispatched(RetentionPeriodElapsed::class);
    Storage::disk('exports')->assertExists('old.json');
});

it('403s the retention console without the capability, and creates a rule with it', function (): void {
    // Two operators, so two admin hosts — `operators.admin_host` is unique.
    [, $reader] = complianceRetentionOperator(['platform.access-admin'], host: 'admin.retention-reader.test');

    $this->actingAs($reader)
        ->get('http://admin.retention-reader.test/admin/retention')
        ->assertForbidden();

    [, $admin] = complianceRetentionOperator();

    $this->actingAs($admin)
        ->get('http://admin.retention.test/admin/retention')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/retention/Index')
            ->has('rules', 2));

    $this->actingAs($admin)
        ->post('http://admin.retention.test/admin/retention', [
            'brand_id' => null,
            'data_category' => 'admission.check-ins',
            'retention_months' => 24,
            'clock_start' => 'event_concluded',
            'description' => 'Check-in records after an event.',
            'active' => true,
        ])
        ->assertRedirect();

    expect(RetentionRule::query()->where('data_category', 'admission.check-ins')->exists())->toBeTrue();
});

it('refuses a second rule for the same category and scope', function (): void {
    [, $admin] = complianceRetentionOperator();

    $this->actingAs($admin)
        ->post('http://admin.retention.test/admin/retention', [
            'data_category' => 'compliance.audit-trail',
            'retention_months' => 6,
            'clock_start' => 'record_age',
            'active' => true,
        ])
        ->assertServerError();

    // No follow-up query: Postgres aborts the surrounding transaction after a
    // constraint violation, and the refusal itself is the assertion. The
    // constraint is `UNIQUE NULLS NOT DISTINCT`, so this covers the
    // operator-wide rows a plain unique index would have let through.
});
