<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Compliance\Data\CheckoutLegalTextRoleData;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Platform\Models\Brand;

/**
 * #200: `CheckoutLegalTexts::SLUGS` decides which texts the checkout asks a
 * payer to agree to, and until now that convention was invisible. A brand
 * whose terms live under the slug `agb` had no consent control at all, and
 * nothing anywhere said so.
 *
 * The console now names the two roles where a policy is created and edited,
 * and says out loud which brands the checkout resolves nothing for.
 *
 * Helper names prefixed `checkoutRole*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
/**
 * A published policy under `$slug`, operator-wide when `$operatorWide`, else
 * owned by whatever brand the caller has put in context.
 */
function checkoutRolePublishedPolicy(string $slug, bool $operatorWide = false): Policy
{
    $policy = Policy::factory()
        ->when($operatorWide, fn ($factory) => $factory->operatorWide())
        ->create(['slug' => $slug, 'name' => ucfirst($slug)]);

    PolicyVersion::factory()
        ->for($policy)
        ->when($operatorWide, fn ($factory) => $factory->operatorWide())
        ->published()
        ->create(['requires_consent' => $slug === 'terms']);

    return $policy;
}

it('names one role per checkout slug, in the order the checkout names them', function (): void {
    $roles = CheckoutLegalTextRoleData::roles();

    expect(array_map(fn (CheckoutLegalTextRoleData $role): string => $role->slug, $roles))
        ->toBe(CheckoutLegalTexts::SLUGS)
        ->and($roles[0]->requiresConsent)->toBeTrue()
        ->and($roles[1]->requiresConsent)->toBeFalse();

    foreach ($roles as $role) {
        expect($role->name)->not->toBe('')
            ->and($role->description)->not->toBe('');
    }

    expect(CheckoutLegalTextRoleData::forSlug('terms'))->not->toBeNull()
        ->and(CheckoutLegalTextRoleData::forSlug('agb'))->toBeNull();
});

it('offers the two checkout roles on the policy list and names the brands the checkout resolves nothing for', function (): void {
    [, $admin] = complianceOperator();

    $covered = Brand::factory()->create(['name' => 'Covered Con']);
    $bare = Brand::factory()->create(['name' => 'Bare Con']);

    $this->withBrand($covered);
    checkoutRolePublishedPolicy('terms');
    checkoutRolePublishedPolicy('withdrawal');
    $this->withBrand($bare);

    // Asserted by brand rather than by position: the factories behind an
    // operator leave brands of their own in the database, and the page
    // deliberately reports every brand it finds a gap for.
    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($bare, $covered): void {
            $page->component('admin/policies/Index')
                ->has('checkoutRoles', 2)
                ->where('checkoutRoles.0.slug', 'terms')
                ->where('checkoutRoles.1.slug', 'withdrawal');

            $gaps = collect($page->toArray()['props']['checkoutGaps']);

            expect($gaps->firstWhere('brandId', $bare->getKey()))
                ->toMatchArray(['brandName' => 'Bare Con', 'missingSlugs' => ['terms', 'withdrawal']])
                ->and($gaps->firstWhere('brandId', $covered->getKey()))->toBeNull();
        });
});

it('treats a brand policy under a checkout slug with no published version as a gap, because the checkout does', function (): void {
    [, $admin] = complianceOperator();

    $brand = Brand::factory()->create(['name' => 'Draft Con']);
    $this->withBrand($brand);

    // The operator publishes both; the brand shadows one with a draft-only
    // policy of its own. `Policy::resolveFor()` picks the brand's row, which
    // has nothing published — so the checkout resolves no text at all.
    checkoutRolePublishedPolicy('terms', operatorWide: true);
    checkoutRolePublishedPolicy('withdrawal', operatorWide: true);

    Policy::factory()->create(['slug' => 'terms', 'name' => 'Our own AGB']);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($brand): void {
            $gaps = collect($page->toArray()['props']['checkoutGaps']);

            expect($gaps->firstWhere('brandId', $brand->getKey()))
                ->toMatchArray(['missingSlugs' => ['terms']]);
        });
});

it('tells the editor which checkout role the policy it is showing plays', function (): void {
    [, $admin] = complianceOperator();

    $this->withBrand(Brand::factory()->create());
    $terms = checkoutRolePublishedPolicy('terms');
    $houseRules = checkoutRolePublishedPolicy('house-rules');

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies/'.$terms->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/policies/Edit')
            ->has('checkoutRoles', 2)
            ->where('checkoutRole.slug', 'terms'));

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies/'.$houseRules->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('checkoutRole', null));
});
