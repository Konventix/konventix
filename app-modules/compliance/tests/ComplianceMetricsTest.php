<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\WithdrawConsent;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Compliance\Metrics\ComplianceMetrics;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Compliance's first numbers (ADR-0059, ADR-0066 spec §10).
 *
 * Prompts and grants share one bounded `surface` set on purpose: the
 * dashboard derives the refusal rate by dividing one by the other
 * (D-CPL-17) and `KonventixConsentGateBlocking` fires on prompts without
 * grants, so two sets that did not line up would silence the alert.
 *
 * No label ever carries a policy slug, a version, a subject or a binding
 * target — all four are operator-defined or per-person, and either one would
 * make the cardinality of these series a function of how much business the
 * operator does (T-11).
 *
 * Helper names prefixed `complianceMetrics*`: Pest helpers are
 * process-global (`.ai/rules/tests.md`).
 */
function complianceMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

function complianceMetricsContractVersion(): PolicyVersion
{
    $policy = Policy::factory()->create(['slug' => 'terms', 'name' => 'Terms']);

    return PolicyVersion::factory()->for($policy)->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);
}

it('counts a prompt by surface and reason', function (): void {
    $brand = $this->withBrand();

    event(new ConsentPromptRaised(
        brandId: $brand->getKey(),
        subjectId: (string) Str::ulid(),
        surface: ConsentPromptRaised::SURFACE_ROUTE_GATE,
        reason: ConsentPromptRaised::REASON_CONTRACT,
    ));

    expect(complianceMetricsScrape())->toContain(
        'konventix_consent_prompts_total{brand="'.$brand->slug.'",surface="route_gate",reason="contract"} 1'
    );
});

it('counts a grant on the same bounded surface set the prompt used', function (): void {
    $brand = $this->withBrand();
    $version = complianceMetricsContractVersion();

    (new GrantConsent)((string) Str::ulid(), $version, 'checkout');
    (new GrantConsent)((string) Str::ulid(), $version, 'registration_form');

    $scrape = complianceMetricsScrape();

    expect($scrape)
        ->toContain('konventix_consent_grants_total{brand="'.$brand->slug.'",surface="checkout"} 1')
        // `registration_form` folds onto the prompt's `registration`.
        ->toContain('konventix_consent_grants_total{brand="'.$brand->slug.'",surface="registration"} 1')
        ->not->toContain('surface="registration_form"');
});

it('folds every capture context onto a prompt surface', function (): void {
    expect(ComplianceMetrics::surfaceForCaptureContext('checkout'))->toBe(ConsentPromptRaised::SURFACE_CHECKOUT)
        ->and(ComplianceMetrics::surfaceForCaptureContext('registration_form'))->toBe(ConsentPromptRaised::SURFACE_REGISTRATION)
        ->and(ComplianceMetrics::surfaceForCaptureContext('login'))->toBe(ConsentPromptRaised::SURFACE_LOGIN)
        // The interstitial is where a gated prompt lands.
        ->and(ComplianceMetrics::surfaceForCaptureContext('consent_form'))->toBe(ConsentPromptRaised::SURFACE_ROUTE_GATE)
        ->and(ComplianceMetrics::surfaceForCaptureContext('something-new'))->toBe('other');
});

it('counts a withdrawal per brand', function (): void {
    $brand = $this->withBrand();
    $policy = Policy::factory()->create(['slug' => 'photo-consent', 'name' => 'Photo consent']);
    $version = PolicyVersion::factory()->for($policy)->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    $consent = (new GrantConsent)((string) Str::ulid(), $version, 'consent_form');
    app(WithdrawConsent::class)($consent);

    expect(complianceMetricsScrape())->toContain(
        'konventix_consent_withdrawals_total{brand="'.$brand->slug.'"} 1'
    );
});

it('reports every brand on the outstanding-contract gauge, zero included', function (): void {
    $brand = $this->withBrand();
    $version = complianceMetricsContractVersion();

    $owing = (string) Str::ulid();
    $settled = (string) Str::ulid();

    // Both are subjects of this brand; only one has agreed to the version
    // that binds.
    (new GrantConsent)($settled, $version, 'checkout');

    $otherPolicy = Policy::factory()->create(['slug' => 'privacy', 'name' => 'Privacy']);
    $otherVersion = PolicyVersion::factory()->for($otherPolicy)->published()->create([
        'requires_consent' => false,
        'lawful_basis' => LawfulBasis::LegitimateInterest,
    ]);
    (new GrantConsent)($owing, $otherVersion, 'consent_form');

    expect(complianceMetricsScrape())->toContain(
        'konventix_outstanding_contract_subjects{brand="'.$brand->slug.'"} 1'
    );
});

it('declares every metric it records', function (): void {
    $names = array_map(
        fn ($definition): string => $definition->name,
        ComplianceMetrics::definitions(),
    );

    expect($names)->toBe([
        'consent_prompts_total',
        'consent_grants_total',
        'consent_withdrawals_total',
        'policy_binding_matches_total',
        'outstanding_contract_subjects',
    ]);

    foreach (ComplianceMetrics::definitions() as $definition) {
        // Bounded label sets only (T-11): a slug, a key, a version or a
        // subject would each be unbounded cardinality.
        expect($definition->labels)->each->toBeIn(['brand', 'surface', 'reason', 'kind']);
    }
});
