<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * `brands.operator_id` was nullable "until provisioning exists" (Platform
 * TODO.md). Provisioning exists now — every brand is created under an operator
 * by `CreateBrand`, and the operator settings page edits the identity that
 * makes it meaningful — so the column stops being optional.
 *
 * This is not tidiness. A brand without an operator has no legal seller, so
 * `OperatorSettings::sellerForBrand()` throws at the moment an invoice is
 * issued: the failure surfaces during settlement, on a real order, rather than
 * when the brand was created. Moving it to the database moves it to the
 * earliest possible point.
 */
it('refuses a brand with no operator at the database level', function (): void {
    DB::table('brands')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Orphan',
        'slug' => 'orphan',
        'currency' => 'EUR',
        'operator_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('gives every factory-made brand an operator', function (): void {
    $brand = Brand::factory()->create();

    expect($brand->operator_id)->not->toBeNull()
        ->and($brand->operator)->toBeInstanceOf(Operator::class);
});

/**
 * One operator per test run unless a test asks for more: the factory reuses
 * whichever already exists rather than minting one per brand, which would
 * quietly turn a single-operator assumption into a multi-operator fixture.
 */
it('reuses an existing operator rather than minting one per brand', function (): void {
    // The base TestCase already created a brand, and therefore an operator, so
    // the question is not "how many exist" but "did making brands make more".
    $before = Operator::query()->count();

    $first = Brand::factory()->create();
    $second = Brand::factory()->create();

    expect($first->operator_id)->toBe($second->operator_id)
        ->and(Operator::query()->count())->toBe($before);
});

it('still lets a test put a brand under a named operator', function (): void {
    $mine = Operator::factory()->create();
    Operator::factory()->create();

    $brand = Brand::factory()->for($mine)->create();

    expect($brand->operator_id)->toBe($mine->getKey());
});

it('resolves a seller for any brand, because every brand now has an operator', function (): void {
    $brand = Brand::factory()->create();

    $seller = app(OperatorSettings::class)
        ->sellerForBrand((string) $brand->getKey());

    expect($seller->legalName)->not->toBeEmpty();
});
