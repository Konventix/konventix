<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Actions\ApproveConsentWithdrawal;
use Konventix\Compliance\Actions\RefuseSubjectRequest;
use Konventix\Compliance\Actions\WithdrawConsent;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0050 §7/§8: withdrawal is self-service where the basis is consent
 * (Art. 7(3) leaves no room otherwise) and a DataSubjectRequest for every
 * other basis.
 *
 * Own helper rather than SubjectRequestConsoleTest's `complianceRequestOperator`:
 * Pest helpers are process-global, so a shared one would tie this file's
 * ability to run alone to another file's load order (.ai/rules/tests.md).
 */
function consentWithdrawalOperator(array $permissions = [
    'platform.access-admin',
    'compliance.view-subject-requests',
    'compliance.handle-subject-requests',
], string $host = 'admin.consent-withdrawal.test'): array
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

it('withdraws immediately where the basis is consent', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Consent]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $result = app(WithdrawConsent::class)($consent);

    expect($result)->toBeInstanceOf(Consent::class)
        ->and($result->withdrawn_at)->not->toBeNull();
});

it('opens a request instead where the basis is contract', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $result = app(WithdrawConsent::class)($consent);

    // Art. 7(3) applies to consent, and this policy's basis is not consent:
    // leaving it is a request the operator answers (ADR-0050 §7).
    expect($result)->toBeInstanceOf(DataSubjectRequest::class)
        ->and($result->type)->toBe(DataSubjectRequestType::ConsentWithdrawal)
        ->and($consent->fresh()->withdrawn_at)->toBeNull();
});

it('does not open a duplicate withdrawal request for a consent already awaiting one', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $first = app(WithdrawConsent::class)($consent);
    $second = app(WithdrawConsent::class)($consent);

    expect($first)->toBeInstanceOf(DataSubjectRequest::class)
        ->and($second)->toBeInstanceOf(DataSubjectRequest::class)
        ->and($second->getKey())->toBe($first->getKey())
        ->and(
            DataSubjectRequest::query()
                ->where('consent_id', $consent->getKey())
                ->where('type', DataSubjectRequestType::ConsentWithdrawal)
                ->count()
        )->toBe(1);
});

it('accepts a pending withdrawal request, withdraws the consent and marks the request fulfilled', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $request = app(WithdrawConsent::class)($consent);

    Event::fake([ConsentWithdrawn::class]);

    $result = app(ApproveConsentWithdrawal::class)($request);

    expect($result->state)->toBe(DataSubjectRequestState::Fulfilled)
        ->and($result->closed_at)->not->toBeNull()
        ->and($consent->fresh()->withdrawn_at)->not->toBeNull();

    Event::assertDispatched(
        ConsentWithdrawn::class,
        fn (ConsentWithdrawn $event): bool => $event->consentId === $consent->getKey(),
    );

    // Idempotent: accepting an already-fulfilled request does not re-withdraw
    // the consent or emit ConsentWithdrawn a second time.
    Event::fake([ConsentWithdrawn::class]);
    $repeat = app(ApproveConsentWithdrawal::class)($result->fresh());

    expect($repeat->state)->toBe(DataSubjectRequestState::Fulfilled);
    Event::assertNotDispatched(ConsentWithdrawn::class);
});

it('refuses to accept an already-refused withdrawal request', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);
    $request = app(WithdrawConsent::class)($consent);

    app(RefuseSubjectRequest::class)($request, 'art_7_3_operator_declines');

    // A refused request has already been closed with a stated reason — a
    // later "accept" must not silently reopen and fulfil it.
    expect(fn () => app(ApproveConsentWithdrawal::class)($request->fresh()))
        ->toThrow(InvalidArgumentException::class);

    expect($consent->fresh()->withdrawn_at)->toBeNull()
        ->and($request->fresh()->state)->toBe(DataSubjectRequestState::Refused);
});

it('turns an invalid-state accept into a validation error from the console, not a 500', function (): void {
    [, $admin] = consentWithdrawalOperator();
    $this->withBrand();

    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);
    $request = app(WithdrawConsent::class)($consent);

    app(RefuseSubjectRequest::class)($request, 'art_7_3_operator_declines');

    $this->actingAs($admin)
        ->post("http://admin.consent-withdrawal.test/admin/subject-requests/{$request->getKey()}/accept-consent-withdrawal")
        ->assertSessionHasErrors('request');

    expect($consent->fresh()->withdrawn_at)->toBeNull();
});

it('lets an operator accept a withdrawal request from the console', function (): void {
    [, $admin] = consentWithdrawalOperator();
    $this->withBrand();

    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $request = app(WithdrawConsent::class)($consent);

    $this->actingAs($admin)
        ->post("http://admin.consent-withdrawal.test/admin/subject-requests/{$request->getKey()}/accept-consent-withdrawal")
        ->assertRedirect();

    expect($request->fresh()->state)->toBe(DataSubjectRequestState::Fulfilled)
        ->and($consent->fresh()->withdrawn_at)->not->toBeNull();
});

it('403s the accept-withdrawal route without the handle capability', function (): void {
    [, $admin] = consentWithdrawalOperator(['platform.access-admin', 'compliance.view-subject-requests']);
    $this->withBrand();

    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);
    $request = app(WithdrawConsent::class)($consent);

    $this->actingAs($admin)
        ->post("http://admin.consent-withdrawal.test/admin/subject-requests/{$request->getKey()}/accept-consent-withdrawal")
        ->assertForbidden();
});

it('flags self-service withdrawal versus a pending request on the privacy page', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $consentBasis = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Consent]);
    $selfServiceConsent = Consent::factory()->create([
        'subject_id' => $user->id,
        'policy_version_id' => $consentBasis->getKey(),
    ]);

    $contractBasis = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $pendingConsent = Consent::factory()->create([
        'subject_id' => $user->id,
        'policy_version_id' => $contractBasis->getKey(),
    ]);

    app(WithdrawConsent::class)($pendingConsent);

    $this->actingAs($user)
        ->get('http://localhost/settings/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('consents', function (Collection $consents) use ($selfServiceConsent, $pendingConsent): bool {
                $selfService = $consents->firstWhere('id', $selfServiceConsent->id);
                $pending = $consents->firstWhere('id', $pendingConsent->id);

                return $selfService['selfServiceWithdrawal'] === true
                    && $selfService['withdrawalPending'] === false
                    && $pending['selfServiceWithdrawal'] === false
                    && $pending['withdrawalPending'] === true;
            }));
});

it('shows no missing-modules warning for a ConsentWithdrawal request in the console', function (): void {
    // OpenSubjectRequest gates the per-module response-seeding loop for
    // ConsentWithdrawal (ADR-0050 §8) — no module was ever owed a response,
    // so diffing the full registry against an empty response set must not
    // read as "every module never addressed this."
    [, $admin] = consentWithdrawalOperator(['platform.access-admin', 'compliance.view-subject-requests']);
    $this->withBrand();

    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);
    $request = app(WithdrawConsent::class)($consent);

    $this->actingAs($admin)
        ->get("http://admin.consent-withdrawal.test/admin/subject-requests/{$request->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/subject-requests/Show')
            ->where('missingModules', []));
});

it('excludes a ConsentWithdrawal request from the privacy page request list', function (): void {
    // The withdrawal already has its home next to the consent it names
    // ("Withdrawal pending") — listing it again here would both duplicate
    // it and mislabel it, since this list only knows export/deletion.
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create([
        'subject_id' => $user->id,
        'policy_version_id' => $version->getKey(),
    ]);

    app(WithdrawConsent::class)($consent);

    $this->actingAs($user)
        ->get('http://localhost/settings/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests', 0));
});
