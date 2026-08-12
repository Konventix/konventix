<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Stands in for a real module's own ServiceProvider registering its
 * GdprHandler from `registerGdprExporters()` — exactly the ADR-0003 pattern
 * every module (Staffing, Commerce, ...) will follow for real once it grows
 * a `Gdpr/` handler.
 */
final class FakeExportingModuleServiceProvider extends ModuleServiceProvider
{
    protected bool $gateable = false;

    public static function moduleName(): string
    {
        return 'fake-exporting-module';
    }

    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new class implements GdprHandler
        {
            public function module(): string
            {
                return 'fake-exporting-module';
            }

            public function exportFor(string $subjectId, string $brandId): array
            {
                return ['probe' => true];
            }

            public function delete(string $subjectId, string $brandId, DeletionMode $mode): void {}
        });
    }
}

it('registers nothing until the module\'s own ServiceProvider boots', function (): void {
    expect(app(GdprHandlerRegistry::class)->has('fake-exporting-module'))->toBeFalse();
});

it('registers a handler when a fake module boots its own ServiceProvider, mirroring ADR-0003', function (): void {
    $registry = app(GdprHandlerRegistry::class);

    (new FakeExportingModuleServiceProvider(app()))->boot();

    expect($registry->has('fake-exporting-module'))->toBeTrue()
        ->and($registry->moduleNames())->toContain('fake-exporting-module');

    $handler = $registry->get('fake-exporting-module');

    expect($handler)->not->toBeNull()
        ->and($handler->exportFor('subject', 'brand'))->toBe(['probe' => true]);
});

it('is a boot-time singleton, so a module registering twice does not create two entries', function (): void {
    $registry = app(GdprHandlerRegistry::class);

    (new FakeExportingModuleServiceProvider(app()))->boot();
    (new FakeExportingModuleServiceProvider(app()))->boot();

    // Real modules (Registration, ...) register their handlers at boot,
    // so assert on the fake's presence and non-duplication rather than an
    // absolute registry size.
    $names = array_values(array_filter(
        $registry->moduleNames(),
        fn (string $name): bool => $name === 'fake-exporting-module',
    ));

    expect($names)->toBe(['fake-exporting-module']);
});
