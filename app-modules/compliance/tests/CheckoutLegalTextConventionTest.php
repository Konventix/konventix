<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

it('resolves every brand\'s gaps in the same number of queries, however many brands there are', function (): void {
    [, $admin] = complianceOperator();

    $countPolicyQueries = function () use ($admin): int {
        $count = 0;

        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (str_contains($query->sql, 'policies') || str_contains($query->sql, 'policy_versions')) {
                $count++;
            }
        });

        test()->actingAs($admin)->get('http://admin.compliance.test/admin/policies')->assertOk();

        // Each call gets its own counter and its own listener, so this is
        // that request's cost alone.
        return $count;
    };

    Brand::factory()->count(2)->create();
    $withTwoBrands = $countPolicyQueries();

    Brand::factory()->count(8)->create();
    $withTenBrands = $countPolicyQueries();

    // The property: the cost of the page does not grow with the number of
    // brands. `forBrand()` per brand made it four queries each.
    expect($withTenBrands)->toBe($withTwoBrands)
        ->and($withTwoBrands)->toBeLessThan(10);
});

/**
 * #222, operator ruling of 2026-09-08: "warn only, but with a bigger
 * hint/problem statement in the UI". No hard refusal at checkout, so the
 * warning has to reach the operator on every page they manage selling from
 * — including the policy editor they land on while fixing it.
 */
it('warns on the policy editor too, for that policy\'s own brand', function (): void {
    [, $admin] = complianceOperator();

    $bare = Brand::factory()->create(['name' => 'Bare Con']);
    $this->withBrand($bare);
    $policy = Policy::factory()->create(['slug' => 'privacy', 'name' => 'Privacy']);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies/'.$policy->getKey().'/edit')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($bare): void {
            $gaps = collect($page->toArray()['props']['checkoutGaps']);

            expect($gaps)->toHaveCount(1)
                ->and($gaps->firstWhere('brandId', $bare->getKey()))
                ->toMatchArray(['brandName' => 'Bare Con', 'missingSlugs' => ['terms', 'withdrawal']]);
        });
});

it('says nothing on the editor of a brand whose checkout resolves both texts', function (): void {
    [, $admin] = complianceOperator();

    $covered = Brand::factory()->create(['name' => 'Covered Con']);
    $this->withBrand($covered);
    checkoutRolePublishedPolicy('terms');
    $policy = checkoutRolePublishedPolicy('withdrawal');

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies/'.$policy->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('checkoutGaps', []));
});

/**
 * The warning's "create this text" link lands on the list with the slug and
 * the brand already chosen — retyping the slug by hand is what produced the
 * `agb` policy no checkout ever found (#200).
 */
it('carries the create intent a warning link arrives with, and refuses a slug the checkout never asks for', function (): void {
    [, $admin] = complianceOperator();

    $brand = Brand::factory()->create(['name' => 'Bare Con']);
    $this->withBrand($brand);

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies?create=withdrawal&brand='.$brand->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createIntent.slug', 'withdrawal')
            ->where('createIntent.brandId', (string) $brand->getKey()));

    $this->actingAs($admin)
        ->get('http://admin.compliance.test/admin/policies?create=agb')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('createIntent', null));
});
