<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Locales\LocaleResolver;
use Konventix\Platform\Locales\Locales;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * ADR-0068 §4: operator → brand → user, each level optional below the
 * operator, an unsupported value at any level ignored.
 */
it('answers the operator locale when brand and user say nothing', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => null]);
    $user = User::factory()->create(['locale' => null]);

    expect(app(LocaleResolver::class)->forUser($user, $brand))->toBe('de');
});

it('lets the brand override the operator', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => 'en']);

    expect(app(LocaleResolver::class)->forBrand($brand))->toBe('en');
});

it('lets the user override the brand', function (): void {
    $operator = Operator::factory()->create(['locale' => 'en']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => 'en']);
    $user = User::factory()->create(['locale' => 'de']);

    expect(app(LocaleResolver::class)->forUser($user, $brand))->toBe('de');
});

it('falls through two nulls to the operator', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => null]);
    $user = User::factory()->create(['locale' => null]);

    expect(app(LocaleResolver::class)->forUser($user, $brand, $operator))->toBe('de');
});

it('skips an unsupported user locale', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => null]);
    $user = User::factory()->create(['locale' => 'fr']);

    expect(app(LocaleResolver::class)->forUser($user, $brand))->toBe('de');
});

it('answers the config default without an operator', function (): void {
    expect(app(LocaleResolver::class)->forUser(null, null))->toBe(config('app.locale'));
});

it('resolves a user by id across brands for queued work', function (): void {
    $operator = Operator::factory()->create(['locale' => 'de']);
    $brand = Brand::factory()->create(['operator_id' => $operator->id, 'locale' => null]);
    $user = User::factory()->create(['locale' => null]);

    expect(app(LocaleResolver::class)->forUserId($user->id, $brand->id))->toBe('de');
});

it('builds the fallback chain through a variant', function (): void {
    config()->set('app.locale_variants', ['de_formal' => 'de']);

    $locales = app(Locales::class);

    expect($locales->chain('de_formal'))->toBe(['de_formal', 'de', 'en'])
        ->and($locales->chain('de'))->toBe(['de', 'en'])
        ->and($locales->chain('en'))->toBe(['en'])
        ->and($locales->base('de_formal'))->toBe('de');
});

it('restores the app locale after within()', function (): void {
    app()->setLocale('en');

    $seen = app(Locales::class)->within('de', fn (): string => app()->getLocale());

    expect($seen)->toBe('de')->and(app()->getLocale())->toBe('en');
});
