<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\WithdrawConsent;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Gdpr\ComplianceGdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * GitLab #76: the operator is one legal entity behind every brand
 * (ADR-0016), so its legal texts are written once and every brand inherits
 * them unless it publishes its own. `policies.brand_id` is nullable for
 * exactly that scope, the same shape `retention_rules` already uses.
 *
 * Helper names prefixed `operatorPolicy*` — Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
function operatorPolicyWide(string $slug, string $name, ?string $content = null): Policy
{
    $policy = Policy::factory()->operatorWide()->create(['slug' => $slug, 'name' => $name]);

    if ($content !== null) {
        operatorPolicyVersion($policy, $content);
    }

    return $policy;
}

function operatorPolicyVersion(Policy $policy, string $content, bool $requiresConsent = true): PolicyVersion
{
    return PolicyVersion::factory()
        ->for($policy)
        ->operatorWide()
        ->published()
        ->create([
            'content' => $content,
            'requires_consent' => $requiresConsent,
        ]);
}

it('resolves the operator-wide policy for a brand that publishes none of its own', function (): void {
    $brand = $this->withBrand();
    operatorPolicyWide('terms', 'Terms of Participation');

    $resolved = Policy::resolveFor($brand->getKey(), 'terms');

    expect($resolved)->not->toBeNull()
        ->and($resolved->brand_id)->toBeNull()
        ->and($resolved->isOperatorWide())->toBeTrue();
});

it('prefers the brand own policy over the operator-wide text of the same slug', function (): void {
    $brand = $this->withBrand();
    operatorPolicyWide('terms', 'Operator Terms');
    $own = Policy::factory()->terms()->create(['name' => 'Brand Terms']);

    $resolved = Policy::resolveFor($brand->getKey(), 'terms');

    expect($resolved?->getKey())->toBe($own->getKey())
        ->and($resolved?->isOperatorWide())->toBeFalse();
});

it('lists one policy per slug for a brand, the own row shadowing the operator-wide one', function (): void {
    $brand = $this->withBrand();
    operatorPolicyWide('terms', 'Operator Terms');
    operatorPolicyWide('withdrawal', 'Withdrawal Information');
    $own = Policy::factory()->terms()->create(['name' => 'Brand Terms']);

    $resolved = Policy::resolvedFor($brand->getKey());

    expect($resolved->pluck('slug')->sort()->values()->all())->toBe(['terms', 'withdrawal'])
        ->and($resolved->firstWhere('slug', 'terms')?->getKey())->toBe($own->getKey());
});

it('renders the operator-wide text on a brand host that has no policy of its own', function (): void {
    $this->withBrand();
    operatorPolicyWide('withdrawal', 'Withdrawal Information', 'No withdrawal for fixed-date events.');

    $this->get('/policies/withdrawal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Show')
            ->where('policy.name', 'Withdrawal Information')
            ->where('policy.scope', 'operator'));
});

it('renders the brand own text instead of the operator-wide one when both exist', function (): void {
    $this->withBrand();
    operatorPolicyWide('terms', 'Operator Terms', 'The operator text.');
    $own = Policy::factory()->terms()->create(['name' => 'Brand Terms']);
    PolicyVersion::factory()->for($own)->published()->create(['content' => 'The brand text.']);

    $this->get('/policies/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('policy.name', 'Brand Terms')
            ->where('policy.scope', 'brand'));
});

it('counts an operator-wide required version as outstanding, and only once when a brand shadows it', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    operatorPolicyWide('terms', 'Operator Terms', 'Operator text.');

    expect(app(OutstandingConsents::class)->subjectHasOutstanding($user->getKey()))->toBeTrue();

    $own = Policy::factory()->terms()->create(['name' => 'Brand Terms']);
    $ownVersion = PolicyVersion::factory()->for($own)->published()->create(['content' => 'Brand text.']);

    $outstanding = app(OutstandingConsents::class)->forSubject($user->getKey());

    // The brand's own version shadows the operator-wide one entirely: a
    // person agrees to one terms document, never to two.
    expect($outstanding)->toHaveCount(1)
        ->and($outstanding->first()?->getKey())->toBe($ownVersion->getKey())
        ->and($outstanding->first()?->policy->name)->toBe('Brand Terms');

    // And with the shadow removed, the operator-wide row is what is owed —
    // the relation load must escape the brand scope to name it.
    $ownVersion->delete();
    $own->delete();

    $fallback = app(OutstandingConsents::class)->forSubject($user->getKey());

    expect($fallback)->toHaveCount(1)
        ->and($fallback->first()?->policy->name)->toBe('Operator Terms');

    // Agreeing to the operator-wide version clears the gate for this brand.
    Consent::factory()->create([
        'subject_id' => $user->getKey(),
        'policy_version_id' => $fallback->first()?->getKey(),
    ]);

    expect(app(OutstandingConsents::class)->subjectHasOutstanding($user->getKey()))->toBeFalse();

    unset($brand);
});

it('keeps a brand policy invisible to another brand while both inherit the same operator text', function (): void {
    $first = $this->withBrand();
    operatorPolicyWide('terms', 'Operator Terms');
    Policy::factory()->terms()->create(['name' => 'First Brand Terms']);

    $second = $this->withBrand(Brand::factory()->create());

    expect(Policy::resolveFor($second->getKey(), 'terms')?->name)->toBe('Operator Terms')
        ->and(Policy::resolveFor($first->getKey(), 'terms')?->name)->toBe('First Brand Terms');
});

it('refuses a second operator-wide policy under the same slug', function (): void {
    $this->withBrand();
    operatorPolicyWide('terms', 'Operator Terms');

    // `UNIQUE NULLS NOT DISTINCT`: a plain unique index treats every NULL as
    // distinct and would leave the operator-wide rows unconstrained — the
    // same reason `retention_rules` writes its constraint by hand.
    expect(fn (): Policy => operatorPolicyWide('terms', 'A second operator text'))
        ->toThrow(QueryException::class);
});

it('never stamps the resolved brand onto an operator-wide row created inside a brand context', function (): void {
    $brand = $this->withBrand();

    expect(app(BrandContext::class)->id())->toBe($brand->getKey());

    $policy = operatorPolicyWide('withdrawal', 'Withdrawal Information', 'Text.');

    expect($policy->fresh()?->brand_id)->toBeNull()
        ->and(PolicyVersion::withoutBrandScope()->where('policy_id', $policy->getKey())->value('brand_id'))
        ->toBeNull();
});

/*
 * The relation loads on the way back out. A Consent is stamped with the
 * brand it was given in, but the version and policy behind it may be
 * operator-wide — so every eager load that walks from a consent to its text
 * has to escape BrandScope or it comes back null on the very brand host that
 * recorded it (.ai/rules/app-modules-src.md).
 */

it('withdraws a consent to an operator-wide version from the brand host that recorded it', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $policy = operatorPolicyWide('photo-consent', 'Photo Consent');
    $version = PolicyVersion::factory()->for($policy)->operatorWide()->published()->create([
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    $consent = app(GrantConsent::class)($user->getKey(), $version, 'privacy_settings');

    app(WithdrawConsent::class)($consent);

    expect($consent->refresh()->withdrawn_at)->not->toBeNull();
});

it('names the operator-wide policy in a subject export', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $policy = operatorPolicyWide('terms', 'Operator Terms');
    $version = PolicyVersion::factory()->for($policy)->operatorWide()->published()->create();

    app(GrantConsent::class)($user->getKey(), $version, 'checkout');

    $export = (new ComplianceGdprHandler)->exportFor(SubjectRef::forUser($user->getKey()));

    expect($export['consents'][0]['policy'])->toBe('terms');
});
