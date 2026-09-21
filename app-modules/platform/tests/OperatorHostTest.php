<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\StaticPage;

/**
 * ADR-0029: the admin console exists only on the operator's admin host; on
 * brand hosts it is a 404, not a login redirect — its existence is nobody's
 * business there.
 */
it('404s admin routes on a brand host', function (): void {
    operatorWithAdmin();

    $this->get('http://localhost/admin')->assertNotFound();
});

it('redirects an unauthenticated admin-host request to login', function (): void {
    operatorWithAdmin();

    $this->get('http://admin.op.test/admin')->assertRedirect('http://admin.op.test/login');
});

it('serves the dashboard to an operator admin', function (): void {
    [, $admin] = operatorWithAdmin();

    $this->actingAs($admin)
        ->get('http://admin.op.test/admin')
        ->assertOk();
});

it('sends the admin host root to the console, and leaves a brand host root alone', function (): void {
    // The admin host has no brand and so no home page: `/` was an empty
    // Welcome page with no link into the console (hotfix 2026-09-13).
    operatorWithAdmin();

    $this->get('http://admin.op.test/')->assertRedirect('http://admin.op.test/admin');
    $this->get('http://localhost/')->assertOk();
});

it('403s an authenticated user without the admin capability', function (): void {
    operatorWithAdmin();

    $this->actingAs(User::factory()->create())
        ->get('http://admin.op.test/admin')
        ->assertForbidden();
});

it('hardens brand-owned queries to empty on the admin host', function (): void {
    [, $admin] = operatorWithAdmin();

    $this->withBrand();
    StaticPage::factory()->published()->create(['slug' => 'leak-probe']);

    // The brand's page renders on the brand host…
    $this->get('http://localhost/pages/leak-probe')->assertOk();

    // …and is unreachable on the admin host: hardened scope, not unscoped.
    $this->actingAs($admin)
        ->get('http://admin.op.test/pages/leak-probe')
        ->assertNotFound();
});

it('lets an operator admin create a brand from the console', function (): void {
    [, $admin] = operatorWithAdmin();

    $this->actingAs($admin)
        ->post('http://admin.op.test/admin/brands', [
            'name' => 'SXLan',
            'slug' => 'sxlan',
            'hosts' => ['sxlan.test', 'www.sxlan.test'],
        ])
        ->assertRedirect();

    $brand = Brand::query()->where('slug', 'sxlan')->first();

    expect($brand)->not->toBeNull()
        ->and($brand->reverb_app_key)->toBeString()
        ->and($brand->domains->pluck('host')->all())->toBe(['sxlan.test', 'www.sxlan.test']);
});
