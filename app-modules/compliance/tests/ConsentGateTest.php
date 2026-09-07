<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The public consent surface: the published policy page, the
 * `require-consent` gate other modules opt their routes into, the consent
 * capture endpoint, and the privacy settings page.
 */
it('shows the published version of a policy to anyone', function (): void {
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'privacy', 'name' => 'Privacy policy']);
    PolicyVersion::factory()->for($policy)->published()->create([
        'version' => '2026-01',
        'content' => "## Data we keep\n\n- your name",
    ]);

    $this->get('http://localhost/policies/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Show')
            ->where('policy.version', '2026-01')
            ->where('policy.html', "<h2>Data we keep</h2>\n<ul>\n<li>your name</li>\n</ul>\n"));
});

it('strips raw HTML out of a policy text rather than rendering it', function (): void {
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'terms']);
    PolicyVersion::factory()->for($policy)->published()->create([
        'content' => "Fine print <script>alert('x')</script>\n\n[link](javascript:alert(1))",
    ]);

    $response = $this->get('http://localhost/policies/terms')->assertOk();

    $html = $response->viewData('page')['props']['policy']['html'];

    expect($html)->not->toContain('<script>')
        ->and($html)->not->toContain('javascript:');
});

it('404s a policy that has only a draft version', function (): void {
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'draft-only']);
    PolicyVersion::factory()->for($policy)->create();

    $this->get('http://localhost/policies/draft-only')->assertNotFound();
});

it('404s a published version whose effective date has not arrived', function (): void {
    $this->withBrand();

    $policy = Policy::factory()->create(['slug' => 'future']);
    PolicyVersion::factory()->for($policy)->published()->create([
        'effective_at' => now()->addWeek(),
    ]);

    $this->get('http://localhost/policies/future')->assertNotFound();
});

it('redirects a gated route to the consent screen and back afterwards', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $policy = Policy::factory()->create(['slug' => 'terms', 'name' => 'Terms']);
    $version = PolicyVersion::factory()->for($policy)->published()->create([
        'requires_consent' => true,
    ]);

    // A gated route standing in for Registration's public form — the gate is
    // opt-in per route (ADR-0003), so the test opts a route in the same way.
    Route::middleware(['web', 'auth', 'require-consent'])
        ->get('/gated-fixture', fn (): string => 'through')
        ->name('gated.fixture');

    $this->actingAs($user)
        ->get('http://localhost/gated-fixture')
        ->assertRedirect(route('policies.required', absolute: false));

    $this->actingAs($user)
        ->get('http://localhost/policies-required')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Required')
            ->has('versions', 1)
            ->where('versions.0.name', 'Terms'));

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$version->id]])
        ->assertRedirect('http://localhost/gated-fixture');

    $this->actingAs($user)
        ->get('http://localhost/gated-fixture')
        ->assertOk()
        ->assertSee('through');
});

it('lets a guest through the gate untouched', function (): void {
    $this->withBrand();

    PolicyVersion::factory()->for(Policy::factory())->published()->create(['requires_consent' => true]);

    Route::middleware(['web', 'require-consent'])
        ->get('/open-fixture', fn (): string => 'through');

    $this->get('http://localhost/open-fixture')->assertOk()->assertSee('through');
});

it('does not gate on an informational policy', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    PolicyVersion::factory()->for(Policy::factory())->published()->create([
        'requires_consent' => false,
    ]);

    Route::middleware(['web', 'auth', 'require-consent'])
        ->get('/informational-fixture', fn (): string => 'through');

    $this->actingAs($user)
        ->get('http://localhost/informational-fixture')
        ->assertOk();
});

it('refuses to record consent to a version that is not published', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $draft = PolicyVersion::factory()->for(Policy::factory())->create();

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$draft->id]])
        ->assertRedirect();

    expect(Consent::query()->count())->toBe(0);
});

it('records one consent for a double-submitted form', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()->for(Policy::factory())->published()->create();

    $this->actingAs($user)->post('http://localhost/consents', ['versionIds' => [$version->id]]);
    $this->actingAs($user)->post('http://localhost/consents', ['versionIds' => [$version->id]]);

    expect(Consent::query()->where('subject_id', $user->id)->count())->toBe(1);
});

it('lists a subject\'s consents on the privacy settings page', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $policy = Policy::factory()->create(['name' => 'Photo consent', 'slug' => 'photo']);
    $version = PolicyVersion::factory()->for($policy)->published()->create(['version' => 'v1']);
    Consent::factory()->create(['subject_id' => $user->id, 'policy_version_id' => $version->id]);
    // Another subject's consent must not leak into this page.
    Consent::factory()->create(['policy_version_id' => $version->id]);

    $this->actingAs($user)
        ->get('http://localhost/settings/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Privacy')
            ->has('consents', 1)
            ->where('consents.0.policyName', 'Photo consent')
            ->where('consents.0.version', 'v1'));
});

it('withdraws own consent and refuses to withdraw another subject\'s', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()->for(Policy::factory())->published()->create();
    $own = Consent::factory()->create(['subject_id' => $user->id, 'policy_version_id' => $version->id]);
    $other = Consent::factory()->create(['policy_version_id' => $version->id]);

    $this->actingAs($user)
        ->delete("http://localhost/consents/{$own->id}")
        ->assertRedirect();

    $this->actingAs($user)
        ->delete("http://localhost/consents/{$other->id}")
        ->assertForbidden();

    expect($own->refresh()->isWithdrawn())->toBeTrue()
        ->and($other->refresh()->isWithdrawn())->toBeFalse();
});

it('re-raises the gate after a required consent is withdrawn', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()->for(Policy::factory())->published()->create([
        'requires_consent' => true,
    ]);
    $consent = Consent::factory()->create([
        'subject_id' => $user->id,
        'policy_version_id' => $version->id,
    ]);

    Route::middleware(['web', 'auth', 'require-consent'])
        ->get('/regate-fixture', fn (): string => 'through');

    $this->actingAs($user)->get('http://localhost/regate-fixture')->assertOk();

    $this->actingAs($user)->delete("http://localhost/consents/{$consent->id}");

    $this->actingAs($user)
        ->get('http://localhost/regate-fixture')
        ->assertRedirect(route('policies.required', absolute: false));
});

it('refuses to record consent to another brand\'s own text', function (): void {
    // The form posts version ids back and the controller escapes BrandScope
    // on purpose (ADR-0010, #76): a version of an *operator-wide* policy
    // carries no `brand_id`, and the scoped query would drop exactly the
    // texts every brand inherits. Escaping it for everything, though, also
    // accepted a neighbouring brand's own published version — a consent row
    // pointing at wording this brand never serves. Only the policies
    // `Policy::resolvedFor()` yields may be agreed to here.
    $mine = $this->withBrand();
    $user = User::factory()->create();

    $this->withBrand(Brand::factory()->create());
    $foreign = PolicyVersion::factory()->for(Policy::factory())->published()->create();

    $this->withBrand($mine);

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$foreign->id]])
        ->assertRedirect();

    expect(Consent::withoutBrandScope()->count())->toBe(0);
});

it('still records consent to the operator-wide text every brand inherits', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $version = PolicyVersion::factory()
        ->for(Policy::factory()->operatorWide())
        ->published()
        ->create(['brand_id' => null]);

    $this->actingAs($user)
        ->post('http://localhost/consents', ['versionIds' => [$version->id]])
        ->assertRedirect();

    expect(Consent::query()->where('policy_version_id', $version->id)->count())->toBe(1);
});
