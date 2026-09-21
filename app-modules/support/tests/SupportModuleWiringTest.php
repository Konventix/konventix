<?php

declare(strict_types=1);

use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Gdpr\SupportGdprHandler;
use Konventix\Support\Providers\SupportServiceProvider;

/**
 * ADR-0063: Support is kernel-style — always active for every brand *and*
 * on the operator console, where `ModuleGate::isActive()` reads "no brand"
 * as inactive for everything that can be gated. A report channel a brand
 * could switch off, or that the console could not carry, is the failure
 * this ADR exists to prevent.
 */
it('is never gateable and is active with no brand resolved', function (): void {
    $gate = app(ModuleGate::class);

    expect($gate->isGateable('support'))->toBeFalse()
        ->and($gate->gateableModuleNames())->not->toContain('support');

    app(BrandContext::class)->forget();

    expect($gate->isActive('support'))->toBeTrue();
});

it('applies no module gate to its routes', function (): void {
    expect((new SupportServiceProvider(app()))->routeMiddleware())->toBe(['web']);
});

it('ships one default role carrying all three capabilities', function (): void {
    $roles = (new SupportServiceProvider(app()))->defaultRoles();

    expect($roles)->toHaveCount(1)
        ->and($roles[0]->key)->toBe('support.desk')
        ->and($roles[0]->name)->toBe('Support Desk')
        ->and($roles[0]->permissions)->toEqualCanonicalizing([
            Permission::ViewRequests->value,
            Permission::TriageRequests->value,
            Permission::ViewReporterContact->value,
        ]);
});

/**
 * Support is always active, so `permissions:sync` materializes the desk for
 * every brand — unlike a gateable module's set, which waits for activation
 * (ADR-0063's consequences, ADR-0045).
 */
it('materializes the Support Desk for every brand without any activation', function (): void {
    $brand = Brand::factory()->create(['slug' => 'support-wiring']);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'support.desk')->exists())->toBeTrue();
});

it('registers its GDPR handler with Compliance', function (): void {
    $registry = app(GdprHandlerRegistry::class);

    expect($registry->moduleNames())->toContain('support')
        ->and($registry->get('support'))->toBeInstanceOf(SupportGdprHandler::class);
});
