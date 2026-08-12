<?php

declare(strict_types=1);

use Konventix\Platform\Theming\ContrastRatio;

it('rates black on white at the WCAG maximum of 21:1', function (): void {
    expect(ContrastRatio::ratio('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.001);
});

it('rates identical colors at 1:1', function (): void {
    expect(ContrastRatio::ratio('hsl(210 50% 50%)', 'hsl(210 50% 50%)'))->toEqualWithDelta(1.0, 0.001);
});

it('is symmetric regardless of argument order', function (): void {
    $a = ContrastRatio::ratio('#123456', '#fedcba');
    $b = ContrastRatio::ratio('#fedcba', '#123456');

    expect($a)->toEqualWithDelta($b, 0.0001);
});

it('parses 3-digit and 6-digit hex identically', function (): void {
    expect(ContrastRatio::ratio('#fff', '#000'))->toEqualWithDelta(21.0, 0.001);
});

it('parses rgb() and rgba()', function (): void {
    expect(ContrastRatio::ratio('rgb(255, 255, 255)', 'rgb(0, 0, 0)'))->toEqualWithDelta(21.0, 0.001);
    expect(ContrastRatio::ratio('rgba(255 255 255 / 1)', 'rgba(0, 0, 0, 1)'))->toEqualWithDelta(21.0, 0.001);
});

it('parses hsl() and hsla() with and without commas', function (): void {
    expect(ContrastRatio::ratio('hsl(0 0% 100%)', 'hsl(0 0% 0%)'))->toEqualWithDelta(21.0, 0.001);
    expect(ContrastRatio::ratio('hsl(0, 0%, 100%)', 'hsla(0, 0%, 0%, 1)'))->toEqualWithDelta(21.0, 0.001);
});

it('recognizes the three supported color shapes and rejects everything else', function (): void {
    expect(ContrastRatio::isColor('#abc'))->toBeTrue();
    expect(ContrastRatio::isColor('#aabbcc'))->toBeTrue();
    expect(ContrastRatio::isColor('rgb(1, 2, 3)'))->toBeTrue();
    expect(ContrastRatio::isColor('hsl(1 2% 3%)'))->toBeTrue();

    expect(ContrastRatio::isColor('not-a-color'))->toBeFalse();
    expect(ContrastRatio::isColor('blue'))->toBeFalse();
    expect(ContrastRatio::isColor(''))->toBeFalse();
    expect(ContrastRatio::isColor('#gggggg'))->toBeFalse();
});

it('throws when asked to compute a ratio for an unparseable color', function (): void {
    expect(fn () => ContrastRatio::ratio('not-a-color', '#ffffff'))
        ->toThrow(InvalidArgumentException::class);
});

it('computes relative luminance at the WCAG-documented endpoints', function (): void {
    expect(ContrastRatio::relativeLuminance(255, 255, 255))->toEqualWithDelta(1.0, 0.001);
    expect(ContrastRatio::relativeLuminance(0, 0, 0))->toEqualWithDelta(0.0, 0.001);
});
