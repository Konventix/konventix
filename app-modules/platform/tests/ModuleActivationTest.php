<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Slots\SlotRegistry;
use Laravel\Pennant\Feature;

/**
 * ADR-0003: registration is unconditional; activation is a request-time gate
 * per brand, enforced at four points — routes, navigation, UI slots, listeners.
 */
it('treats kernel and core modules as always active', function (string $module): void {
    $gate = app(ModuleGate::class);

    expect($gate->isGateable($module))->toBeFalse()
        ->and($gate->isActive($module))->toBeTrue();
})->with(['platform', 'identity', 'event', 'compliance', 'messaging', 'registration', 'admission', 'commerce', 'content']);

it('treats the third tier as gateable and off until a brand opts in', function (string $module): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $gate = app(ModuleGate::class);

    expect($gate->isGateable($module))->toBeTrue()
        ->and($gate->isActive($module))->toBeFalse();

    Feature::for($brand)->activate($gate->featureName($module));

    expect($gate->isActive($module))->toBeTrue();
})->with(['staffing', 'resources', 'competition', 'game-ops', 'agenda', 'community', 'sponsoring', 'newsletter', 'extensibility', 'support']);

it('activates a module for one brand without activating it for another', function (): void {
    $enabled = Brand::factory()->create();
    $disabled = Brand::factory()->create();
    $gate = app(ModuleGate::class);
    $context = app(BrandContext::class);

    Feature::for($enabled)->activate($gate->featureName('staffing'));

    $context->set($enabled);
    expect($gate->isActive('staffing'))->toBeTrue();

    $context->set($disabled);
    expect($gate->isActive('staffing'))->toBeFalse();
});

it('reports a gateable module as inactive when no brand is resolved', function (): void {
    app(BrandContext::class)->forget();

    expect(app(ModuleGate::class)->isActive('staffing'))->toBeFalse();
});

it('discovers every installed module without a central list', function (): void {
    expect(app(ModuleGate::class)->gateableModuleNames())
        ->toHaveCount(10)
        ->toContain('game-ops');
});

it('404s a route in a module the brand has not activated', function (): void {
    $brand = Brand::factory()->create()->refresh();
    $brand->domains()->create(['host' => 'gated.test', 'is_primary' => true]);

    Route::middleware(['web', 'ensure-module:staffing'])
        ->get('/gate-probe', fn (): string => 'reached');

    // The brand comes from the request host — ResolveBrand fronts the `web`
    // group, so the gate must resolve against gated.test's brand.
    $this->get('http://gated.test/gate-probe')->assertNotFound();

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    $this->get('http://gated.test/gate-probe')->assertOk()->assertSee('reached');
});

it('hides navigation entries for inactive modules', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $registry = app(NavigationRegistry::class);
    $registry->add(
        new NavigationItem(module: 'staffing', label: 'Shifts', url: '/shifts'),
        new NavigationItem(module: 'identity', label: 'Profile', url: '/settings/profile'),
    );

    // Narrowed to the two entries under test: the brand area carries real
    // registrations of its own now (the topbar's navigation), and this test
    // is about gating rather than about everything in the registry.
    $underTest = fn (): array => $registry->visible()
        ->pluck('label')
        ->intersect(['Shifts', 'Profile'])
        ->values()
        ->all();

    expect($underTest())->toBe(['Profile']);

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    expect($underTest())->toEqualCanonicalizing(['Profile', 'Shifts']);
});

it('hides UI slot contributions for inactive modules', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $slots = app(SlotRegistry::class);
    $slots->add('event.sidebar', 'staffing', 'ShiftSummary');
    $slots->add('event.sidebar', 'content', 'LatestNews');

    expect($slots->for('event.sidebar')->pluck('component')->all())->toBe(['LatestNews']);

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    expect($slots->for('event.sidebar')->pluck('component')->all())
        ->toEqualCanonicalizing(['LatestNews', 'ShiftSummary']);
});

/**
 * A public page's slot contributions have to be *in* the first paint, so a
 * contributing module hands over a closure instead of a literal prop-bag and
 * the rendering controller resolves it with that page's context.
 */
it('resolves a closure prop-bag against the render context', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $slots = app(SlotRegistry::class);
    $slots->add(
        slot: 'test.props.slot',
        module: 'content',
        component: 'LatestNews',
        props: static fn (array $context): array => ['for' => $context['eventId']],
    );

    expect($slots->for('test.props.slot', ['eventId' => 'evt-1'])->first()['props'])
        ->toBe(['for' => 'evt-1']);
});

it('leaves a literal prop-bag exactly as it was registered', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $slots = app(SlotRegistry::class);
    $slots->add('test.props.slot', 'content', 'LatestNews', ['limit' => 3]);

    expect($slots->for('test.props.slot', ['eventId' => 'evt-1'])->first()['props'])
        ->toBe(['limit' => 3]);
});

/**
 * The closure is the expensive half of a contribution — it queries. An
 * inactive module's must never run at all, which is why resolution happens
 * after the activation filter rather than alongside it.
 */
it('never runs an inactive module\'s closure', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $ran = false;

    $slots = app(SlotRegistry::class);
    $slots->add(
        slot: 'test.props.slot',
        module: 'staffing',
        component: 'ShiftSummary',
        props: static function (array $context) use (&$ran): array {
            $ran = true;

            return [];
        },
    );

    $slots->for('test.props.slot', ['eventId' => 'evt-1'])->all();

    expect($ran)->toBeFalse();

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    $slots->for('test.props.slot', ['eventId' => 'evt-1'])->all();

    expect($ran)->toBeTrue();
});

/**
 * Webhooks, the scheduler and console requests on the operator host run with
 * no brand in context, but their event payloads name one. The gate has to
 * answer for that id without anyone setting the ambient brand first.
 */
it('answers for an explicit brand id while no brand is in context', function (): void {
    app(BrandContext::class)->forget();

    $brand = Brand::factory()->create();
    $gate = app(ModuleGate::class);

    expect($gate->isActiveForBrand('resources', $brand->getKey()))->toBeFalse();

    Feature::for($brand)->activate($gate->featureName('resources'));

    expect($gate->isActiveForBrand('resources', $brand->getKey()))->toBeTrue()
        // The ambient answer is still "no brand, so no": the explicit form
        // never leaks into the context.
        ->and($gate->isActive('resources'))->toBeFalse()
        ->and(app(BrandContext::class)->current())->toBeNull();
});

it('answers per brand id, not for the brand in context', function (): void {
    $enabled = Brand::factory()->create();
    $disabled = Brand::factory()->create();
    $gate = app(ModuleGate::class);

    Feature::for($enabled)->activate($gate->featureName('resources'));
    app(BrandContext::class)->set($enabled);

    expect($gate->isActiveForBrand('resources', $disabled->getKey()))->toBeFalse()
        ->and($gate->isActiveForBrand('resources', $enabled->getKey()))->toBeTrue();
});

it('treats an unknown brand id as inactive and a kernel module as always active', function (): void {
    $gate = app(ModuleGate::class);
    $unknown = (string) Str::ulid();

    expect($gate->isActiveForBrand('resources', $unknown))->toBeFalse()
        ->and($gate->isActiveForBrand('event', $unknown))->toBeTrue();
});
