<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Events\PolicyPublished;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: Compliance's operator-console surface — the policy list, the
 * editor, drafting a version and publishing it.
 *
 * Helper name prefixed with the module: Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
function complianceOperator(array $permissions = [
    'platform.access-admin',
    'compliance.view-policies',
    'compliance.manage-policies',
    'compliance.publish-policy',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.compliance.test'])->save();

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

it('404s the policy console on a brand host', function (): void {
    complianceOperator();

    $this->get('http://localhost/admin/policies')->assertNotFound();
});

it('403s an admin without the view-policies capability', function (): void {
    [, $admin] = complianceOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies')
        ->assertForbidden();
});

it('lists policies with their published version across brands', function (): void {
    [, $admin] = complianceOperator();

    $brand = $this->withBrand();
    $policy = Policy::factory()->create(['name' => 'Privacy policy', 'slug' => 'privacy']);
    PolicyVersion::factory()->for($policy)->published()->create(['version' => '2026-01']);
    PolicyVersion::factory()->for($policy)->create(['version' => '2026-02']);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/policies/Index')
            ->has('policies.data', 1)
            ->where('policies.data.0.brandName', $brand->name)
            ->where('policies.data.0.publishedVersion', '2026-01')
            ->where('policies.data.0.versions', 2));
});

it('creates a policy and lands on its editor', function (): void {
    [, $admin] = complianceOperator();
    $brand = $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.compliance.test/admin/policies', [
            'brand_id' => $brand->id,
            'name' => 'House rules',
            'slug' => 'house-rules',
        ])
        ->assertRedirect();

    $policy = Policy::withoutBrandScope()->firstOrFail();

    expect($policy->slug)->toBe('house-rules')
        ->and($policy->brand_id)->toBe($brand->id);
});

it('opens the editor on the draft version and counts its agreements', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create();
    $published = PolicyVersion::factory()->for($policy)->published()->create(['version' => 'v1']);
    PolicyVersion::factory()->for($policy)->create(['version' => 'v2', 'content' => '## Heading']);
    Consent::factory()->count(2)->create(['policy_version_id' => $published->id]);

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/policies/Edit')
            ->where('policy.slugFrozen', true)
            ->has('versions', 2)
            ->where('selected.version', 'v2')
            ->where('selected.editable', true)
            // Markdown is rendered server-side for the preview.
            ->where('selected.preview', "<h2>Heading</h2>\n"));
});

it('refuses to edit a published version', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $version = PolicyVersion::factory()->for(Policy::factory())->published()->create([
        'version' => 'v1',
        'content' => 'Original text',
    ]);

    $this->actingAs($admin)
        ->put("http://admin.compliance.test/admin/policy-versions/{$version->id}", [
            'version' => 'v1',
            'content' => 'Rewritten text',
            'requiresConsent' => true,
            'lawfulBasis' => 'consent',
        ])
        ->assertServerError();

    expect($version->refresh()->content)->toBe('Original text');
});

it('publishes a draft, supersedes the previous version and emits PolicyPublished', function (): void {
    [, $admin] = complianceOperator();
    $brand = $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'terms']);
    $old = PolicyVersion::factory()->for($policy)->published()->create(['version' => 'v1']);
    $draft = PolicyVersion::factory()->for($policy)->create(['version' => 'v2']);

    Event::fake([PolicyPublished::class]);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$draft->id}/publish", [])
        ->assertRedirect();

    // The supersede update runs on the admin host, where BrandScope hardens
    // to zero rows — the regression this asserts is two published versions
    // of one policy standing at once (.ai/rules).
    expect($old->refresh()->state)->toBe(PolicyVersionState::Superseded)
        ->and($draft->refresh()->state)->toBe(PolicyVersionState::Published);

    Event::assertDispatched(
        PolicyPublished::class,
        fn (PolicyPublished $event): bool => $event->versionId === $draft->id
            && $event->slug === 'terms'
            && $event->brandId === $brand->id,
    );
});

it('403s publishing without the publish-policy capability', function (): void {
    [, $admin] = complianceOperator(['platform.access-admin', 'compliance.view-policies', 'compliance.manage-policies']);
    $this->withBrand();

    $draft = PolicyVersion::factory()->for(Policy::factory())->create();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$draft->id}/publish", [])
        ->assertForbidden();

    expect($draft->refresh()->state)->toBe(PolicyVersionState::Draft);
});

it('freezes the slug once a version has been published', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'terms', 'name' => 'Terms']);
    PolicyVersion::factory()->for($policy)->published()->create();

    $this->actingAs($admin)
        ->put("http://admin.compliance.test/admin/policies/{$policy->id}", [
            'name' => 'Terms of participation',
            'slug' => 'terms-of-participation',
        ])
        ->assertServerError();

    // The name would have been editable on its own; the refusal is the slug.
    expect($policy->refresh()->slug)->toBe('terms')
        ->and($policy->name)->toBe('Terms');
});

/*
|--------------------------------------------------------------------------
| Operator-wide policies (GitLab #76)
|--------------------------------------------------------------------------
|
| The operator is one legal entity behind all its brands (ADR-0016), so the
| console writes its AGB and Widerrufsbelehrung once and every brand inherits
| them. The list has to say which scope a row has, and whether a brand has
| shadowed an operator text with its own — a legal reviewer who cannot see
| that is reading a list that lies by omission.
|
*/

it('creates an operator-wide policy when no brand is chosen', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.compliance.test/admin/policies', [
            'brand_id' => null,
            'name' => 'Withdrawal Information',
            'slug' => 'withdrawal',
        ])
        ->assertRedirect();

    $policy = Policy::withoutBrandScope()->where('slug', 'withdrawal')->sole();

    expect($policy->brand_id)->toBeNull()
        ->and($policy->isOperatorWide())->toBeTrue();
});

it('drafts and publishes a version of an operator-wide policy without stamping a brand', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->operatorWide()->withdrawal()->create();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->id}/versions", [
            'version' => 'v1.0',
            'content' => 'Placeholder text.',
            'requiresConsent' => false,
            'lawfulBasis' => 'contract',
        ])
        ->assertRedirect();

    $version = PolicyVersion::withoutBrandScope()->where('policy_id', $policy->id)->sole();

    expect($version->brand_id)->toBeNull();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$version->id}/publish", [])
        ->assertRedirect();

    expect($version->refresh()->state)->toBe(PolicyVersionState::Published)
        ->and($version->brand_id)->toBeNull();
});

it('marks each row with its scope and says when a brand shadows an operator text', function (): void {
    [, $admin] = complianceOperator();
    $brand = $this->withBrand();

    Policy::factory()->operatorWide()->terms()->create(['name' => 'Operator Terms']);
    Policy::factory()->operatorWide()->withdrawal()->create();
    Policy::factory()->terms()->create(['name' => 'Brand Terms']);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies?sort=slug&dir=asc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/policies/Index')
            ->has('policies.data', 3)
            // Ordered by slug: the two `terms` rows, then `withdrawal`.
            ->where('policies.data.0.scope', 'operator')
            ->where('policies.data.0.brandName', 'Operator-wide')
            // An operator text a brand has overridden binds fewer brands
            // than it looks like it does.
            ->where('policies.data.0.shadowedByBrands', 1)
            ->where('policies.data.1.scope', 'brand')
            ->where('policies.data.1.brandName', $brand->name)
            ->where('policies.data.1.shadowsOperator', true)
            ->where('policies.data.2.slug', 'withdrawal')
            ->where('policies.data.2.shadowedByBrands', 0));
});

it('shows the scope on the editor of an operator-wide policy', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->operatorWide()->withdrawal()->create();

    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('policy.scope', 'operator')
            ->where('policy.brandName', 'Operator-wide'));
});
