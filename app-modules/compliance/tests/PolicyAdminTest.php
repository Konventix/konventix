<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Events\PolicyPublished;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Platform\Models\Operator;

/**
 * ADR-0029: Compliance's operator-console surface — the policy list, the
 * editor, drafting a version and publishing it.
 *
 * Helper name prefixed with the module: Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
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
    // Dated before it is published: the console saves the draft's effective
    // date, and publishing takes the stored one.
    $draft = PolicyVersion::factory()->for($policy)->create([
        'version' => 'v2',
        'effective_at' => now()->subMinute(),
    ]);

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
            'effectiveAt' => now()->subMinute()->toIso8601String(),
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

/*
|--------------------------------------------------------------------------
| Effective dates are instants, not the operator's wall clock read as UTC
|--------------------------------------------------------------------------
|
| The console's `datetime-local` control hands back a bare `YYYY-MM-DDTHH:MM`
| in the operator's own zone. Interpreting that string in `config('app.timezone')`
| (UTC) dates every "from now on" version one offset into the future — and
| `Policy::currentVersion()` filters on `effective_at <= now()`, so
| `/policies/{slug}` 404s for exactly that long. The page therefore sends an
| absolute instant and the server stores it as one.
|
*/

it('stores the effective date as the instant the operator meant, not their wall clock read as UTC', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'agb']);

    // What the browser sends once the wall clock has been resolved against
    // the operator's own zone — Berlin is UTC+1/+2, so the offset is the
    // whole bug.
    $local = CarbonImmutable::create(2026, 9, 7, 22, 44, 0, 'Europe/Berlin');

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->id}/versions", [
            'version' => '2026-001',
            'content' => 'Die Bedingungen.',
            'requiresConsent' => false,
            'lawfulBasis' => 'contract',
            'effectiveAt' => $local->toIso8601String(),
        ])
        ->assertRedirect();

    $version = PolicyVersion::withoutBrandScope()->where('policy_id', $policy->id)->sole();

    expect($version->effective_at->utc()->toDateTimeString())->toBe('2026-09-07 20:44:00');

    // ... and the editor hands it back with the offset in the transport, so
    // the browser can put the same wall clock into the field again.
    $this->actingAs($admin)
        ->get("http://admin.compliance.test/admin/policies/{$policy->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('selected.effectiveAt', $local->utc()->toIso8601String())
            ->where('versions.0.effectiveAt', $local->utc()->toIso8601String()));
});

it('serves a version published as "now" in the operator timezone immediately', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'agb', 'name' => 'AGB']);

    // "Now", typed into the form by an operator sitting in Berlin. Truncated
    // to the minute the way `datetime-local` truncates it, so the instant is
    // already in the past by the time the request lands.
    $now = CarbonImmutable::now('Europe/Berlin')->startOfMinute();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->id}/versions", [
            'version' => '2026-001',
            'content' => 'Die Bedingungen.',
            'requiresConsent' => false,
            'lawfulBasis' => 'contract',
            'effectiveAt' => $now->toIso8601String(),
        ])
        ->assertRedirect();

    $version = PolicyVersion::withoutBrandScope()->where('policy_id', $policy->id)->sole();

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$version->id}/publish", [
            'effectiveAt' => $now->toIso8601String(),
        ])
        ->assertRedirect();

    // The observation this test exists for: two hours of 404 on a text that
    // was published a second ago.
    $this->get('http://localhost/policies/agb')->assertOk();
});

/*
|--------------------------------------------------------------------------
| The editor's form state, and publishing without a date
|--------------------------------------------------------------------------
|
| Inertia reuses the page component across a redirect that renders the same
| page, so the editor's `useForm` kept the label of the version opened before
| the new draft and wrote it onto the new row — a violation of
| `policy_versions(policy_id, version)` straight out of `UpdatePolicyVersion`,
| i.e. a 500 with the draft already standing. The page re-seeds its form now;
| these are the two server-side halves: a duplicate label is a form error, and
| a version cannot publish without an effective date (GitLab #201, #203).
|
*/

it('rejects a duplicate version label on save as a form error, not a constraint violation', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create();
    PolicyVersion::factory()->for($policy)->published()->create(['version' => '2026-001']);
    $draft = PolicyVersion::factory()->for($policy)->create(['version' => '2026-002']);

    $this->actingAs($admin)
        ->put("http://admin.compliance.test/admin/policy-versions/{$draft->id}", [
            'version' => '2026-001',
            'content' => 'Die neuen Bedingungen.',
            'requiresConsent' => true,
            'lawfulBasis' => 'consent',
        ])
        ->assertInvalid(['version']);

    expect($draft->refresh()->version)->toBe('2026-002');
});

it('lets a draft keep its own label when nothing else changes', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $draft = PolicyVersion::factory()->for(Policy::factory())->create(['version' => '2026-001']);

    $this->actingAs($admin)
        ->put("http://admin.compliance.test/admin/policy-versions/{$draft->id}", [
            'version' => '2026-001',
            'content' => 'Ueberarbeitet.',
            'requiresConsent' => true,
            'lawfulBasis' => 'consent',
        ])
        ->assertValid();

    expect($draft->refresh()->content)->toBe('Ueberarbeitet.');
});

it('rejects a duplicate version label when drafting, leaving no half-created version', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $policy = Policy::factory()->create();
    PolicyVersion::factory()->for($policy)->create(['version' => '2026-001']);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policies/{$policy->id}/versions", [
            'version' => '2026-001',
            'content' => 'Ein zweiter Entwurf.',
            'requiresConsent' => true,
            'lawfulBasis' => 'consent',
        ])
        ->assertInvalid(['version']);

    expect(PolicyVersion::withoutBrandScope()->where('policy_id', $policy->id)->count())->toBe(1);
});

it('refuses to publish a version that has no effective date', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $draft = PolicyVersion::factory()->for(Policy::factory())->create(['effective_at' => null]);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$draft->id}/publish", [])
        ->assertInvalid(['effectiveAt']);

    // The silent `?? now()` fallback published a text nobody had dated, which
    // is how a version ended up effective at the second it was published.
    expect($draft->refresh()->state)->toBe(PolicyVersionState::Draft)
        ->and($draft->effective_at)->toBeNull();
});

it('publishes with the date stored on the draft when the request carries none', function (): void {
    [, $admin] = complianceOperator();
    $this->withBrand();

    $effectiveAt = now()->subDay()->startOfMinute();
    $draft = PolicyVersion::factory()->for(Policy::factory())->create(['effective_at' => $effectiveAt]);

    $this->actingAs($admin)
        ->post("http://admin.compliance.test/admin/policy-versions/{$draft->id}/publish", [])
        ->assertRedirect();

    expect($draft->refresh()->state)->toBe(PolicyVersionState::Published)
        ->and($draft->effective_at->toDateTimeString())->toBe($effectiveAt->toDateTimeString());
});
