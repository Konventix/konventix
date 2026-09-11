<?php

declare(strict_types=1);

use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\Concerns\ShortCircuitsInactiveModule;

/**
 * The fourth ADR-0003 enforcement point: a gateable module's listener does
 * nothing while the module is off for the brand in context.
 */
function gatedProbe(): object
{
    return new class
    {
        use ShortCircuitsInactiveModule;

        public bool $ran = false;

        public function handle(): void
        {
            if ($this->moduleIsInactive()) {
                return;
            }

            $this->ran = true;
        }

        protected function moduleName(): string
        {
            return 'competition';
        }
    };
}

it('short-circuits while the module is inactive for the brand', function (): void {
    $this->withBrand();

    $listener = gatedProbe();
    $listener->handle();

    expect($listener->ran)->toBeFalse();
});

it('runs once the brand activates the module', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $listener = gatedProbe();
    $listener->handle();

    expect($listener->ran)->toBeTrue();
});

it('short-circuits with no brand in context at all', function (): void {
    $listener = gatedProbe();
    $listener->handle();

    expect($listener->ran)->toBeFalse();
});

/**
 * The brand-id form of the gate, for listeners on events that fire with no
 * ambient brand (webhook, scheduler): the payload's brand id is the scope.
 */
function gatedBrandProbe(): object
{
    return new class
    {
        use ShortCircuitsInactiveModule;

        public ?bool $answer = null;

        public function handle(string $brandId): void
        {
            $this->answer = $this->moduleActiveForBrand($brandId);
        }

        protected function moduleName(): string
        {
            return 'competition';
        }
    };
}

it('answers for the payload brand id with no brand in context at all', function (): void {
    $brand = Brand::factory()->create();

    $listener = gatedBrandProbe();
    $listener->handle($brand->getKey());

    expect($listener->answer)->toBeFalse();

    $this->activateModule('competition', $brand);
    app(BrandContext::class)->forget();

    $listener->handle($brand->getKey());

    expect($listener->answer)->toBeTrue();
});
