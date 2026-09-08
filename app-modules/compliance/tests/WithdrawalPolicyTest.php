<?php

declare(strict_types=1);

use Konventix\Compliance\Database\Seeders\OperatorLegalTextsSeeder;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Platform\Brands\BrandContext;

/**
 * GitLab #76: until this landed, `grep -ri widerruf` found nothing in the
 * repository — no withdrawal information at all, and therefore no notice of
 * the § 312g Abs. 2 Nr. 9 BGB exemption that applies to a leisure event
 * with a fixed date.
 *
 * The two canonical slugs the public purchase paths ask for are `terms`
 * (AGB) and `withdrawal` (Widerrufsbelehrung); `terms` was already the slug
 * the factories and the demo seeder used, `withdrawal` is new beside it.
 *
 * Helper names prefixed `withdrawalPolicy*` — Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
it('names terms and withdrawal as the slugs a purchase path asks for', function (): void {
    expect(CheckoutLegalTexts::SLUGS)->toBe(['terms', 'withdrawal']);
});

it('states the fixed-date leisure event exemption with its statute', function (): void {
    expect(CheckoutLegalTexts::EXEMPTION_NOTICE)
        ->toContain('§ 312g Abs. 2 Nr. 9 BGB')
        ->toContain('no right of withdrawal');
});

it('resolves both purchase texts for a brand, falling back to the operator', function (): void {
    $brand = $this->withBrand();

    $terms = Policy::factory()->operatorWide()->terms()->create();
    PolicyVersion::factory()->for($terms)->operatorWide()->published()->create(['version' => 'v1.0']);

    $withdrawal = Policy::factory()->withdrawal()->create();
    PolicyVersion::factory()->for($withdrawal)->published()->create(['version' => 'w1.0']);

    $resolved = app(CheckoutLegalTexts::class)->forBrand($brand->getKey());

    expect($resolved)->toHaveCount(2)
        ->and($resolved[0]['slug'])->toBe('terms')
        ->and($resolved[0]['scope'])->toBe('operator')
        ->and($resolved[0]['version'])->toBe('v1.0')
        ->and($resolved[0]['url'])->toBe('/policies/terms')
        ->and($resolved[1]['slug'])->toBe('withdrawal')
        ->and($resolved[1]['scope'])->toBe('brand')
        ->and($resolved[1]['version'])->toBe('w1.0');
});

it('reports nothing for a fresh instance where neither text is published', function (): void {
    $brand = $this->withBrand();

    // A policy row with only a draft is not a published text, and a checkout
    // must not claim the payer agreed to one.
    $terms = Policy::factory()->operatorWide()->terms()->create();
    PolicyVersion::factory()->for($terms)->operatorWide()->create(['state' => PolicyVersionState::Draft]);

    expect(app(CheckoutLegalTexts::class)->forBrand($brand->getKey()))->toBe([]);
});

it('seeds operator-wide placeholders that say they are placeholders and carry the exemption', function (): void {
    $this->withBrand();

    app(OperatorLegalTextsSeeder::class)->run();

    // Seeded operator-wide even though a brand happens to be resolved: the
    // texts belong to the operator, not to whichever brand ran the seed.
    expect(app(BrandContext::class)->id())->not->toBeNull();

    $policies = Policy::withoutBrandScope()->whereNull('brand_id')->get();

    // `privacy` joined the pair in #184: the registration page names the
    // controller under Art. 13 DSGVO and links here, and on a fresh instance
    // that link went nowhere.
    expect($policies->pluck('slug')->sort()->values()->all())->toBe(['privacy', 'terms', 'withdrawal']);

    $withdrawal = $policies->firstWhere('slug', 'withdrawal');
    $version = PolicyVersion::withoutBrandScope()->where('policy_id', $withdrawal?->getKey())->sole();

    expect($version->state)->toBe(PolicyVersionState::Published)
        ->and($version->brand_id)->toBeNull()
        ->and($version->content)->toContain('§ 312g Abs. 2 Nr. 9 BGB')
        // Nothing in the repository may pass itself off as the operator's
        // real legal text (#76: the real ones are blocked on #91).
        ->and($version->content)->toContain('PLACEHOLDER');

    $terms = PolicyVersion::withoutBrandScope()
        ->where('policy_id', $policies->firstWhere('slug', 'terms')?->getKey())
        ->sole();

    expect($terms->content)->toContain('PLACEHOLDER')
        ->and($terms->requires_consent)->toBeTrue();

    $privacy = PolicyVersion::withoutBrandScope()
        ->where('policy_id', $policies->firstWhere('slug', 'privacy')?->getKey())
        ->sole();

    expect($privacy->content)->toContain('PLACEHOLDER')
        // An information duty, not a consent one — and marking it
        // `requires_consent` would put it in front of every signed-in
        // visitor through the `require-consent` gate.
        ->and($privacy->requires_consent)->toBeFalse()
        ->and($privacy->state)->toBe(PolicyVersionState::Published);
});

it('re-seeds without duplicating a policy or drafting a second version', function (): void {
    $this->withBrand();

    app(OperatorLegalTextsSeeder::class)->run();
    app(OperatorLegalTextsSeeder::class)->run();

    expect(Policy::withoutBrandScope()->whereNull('brand_id')->count())->toBe(3)
        ->and(PolicyVersion::withoutBrandScope()->whereNull('brand_id')->count())->toBe(3);
});
