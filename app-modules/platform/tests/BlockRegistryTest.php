<?php

declare(strict_types=1);

use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Data\Blocks\TextBlockData;

/**
 * The block-type registry is the Phase 1 proof that a module can extend
 * Platform without Platform knowing about it (ADR-0003): types register at
 * boot, rendering gates them per brand.
 */
function fakeBlockType(string $key, string $module): BlockType
{
    return new class($key, $module) implements BlockType
    {
        public function __construct(
            private readonly string $blockKey,
            private readonly string $owningModule,
        ) {}

        public function key(): string
        {
            return $this->blockKey;
        }

        public function module(): string
        {
            return $this->owningModule;
        }

        public function component(): string
        {
            return 'blocks/Fake';
        }

        public function dataClass(): string
        {
            return TextBlockData::class;
        }
    };
}

it('registers platform\'s text block at boot', function (): void {
    expect(app(BlockRegistry::class)->all())->toHaveKey('text');
});

it('rejects a duplicate block key', function (): void {
    app(BlockRegistry::class)->register(fakeBlockType('text', 'competition'));
})->throws(InvalidArgumentException::class);

it('resolves a kernel module\'s block without any feature flag', function (): void {
    $this->withBrand();

    expect(app(BlockRegistry::class)->resolveActive('text'))->not->toBeNull();
});

it('hides a gateable module\'s block until the brand activates the module', function (): void {
    $registry = app(BlockRegistry::class);
    $registry->register(fakeBlockType('bracket', 'competition'));

    $brand = $this->withBrand();

    expect($registry->resolveActive('bracket'))->toBeNull();

    $this->activateModule('competition', $brand);

    expect($registry->resolveActive('bracket'))->not->toBeNull();
});

it('resolves an unknown key to null instead of failing the page', function (): void {
    $this->withBrand();

    expect(app(BlockRegistry::class)->resolveActive('no-such-block'))->toBeNull();
});
