<?php

declare(strict_types=1);

use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;
use Laravel\Pennant\Feature;

/*
 * The `--rush` fixture, alone in its file: it seeds 1,200 members twice, once
 * for the shape and once to prove the seed is idempotent, and at 37s it was
 * more than a third of the 92s file it came from (#262). On its own it no
 * longer holds the other nine tests behind it.
 */
// The seed writes real files through StoreMediaUpload; without a fake disk
// every run of this suite litters the configured media storage. Faked by the
// deployment's configured name rather than 'media' literally: a checkout that
// points MEDIA_DISK at S3 must still be tested against the disk uploads
// actually land on.
beforeEach(function (): void {
    Storage::fake(mediaDiskNameForTest());
});

/**
 * The rush fixture (ADR-0047 point 6, `--rush`): a brand a k6 run can hit
 * with a real HTTP client, a capacity-capped instant-settlement ticket, a
 * limited-stock shop item and 1,200 login-ready members. `--rush` alone
 * skips the interactive archetype prompts entirely — this is a fixture
 * step, not a demo walk — so no `expectsQuestion()` chain is needed here,
 * unlike every other test in this file.
 */
it('seeds the rush brand, its capped ticket and 1,200 members', function () {
    releaseLocalhostForTest();

    $this->artisan('konventix:seed', ['--rush' => true])->assertSuccessful();

    $brand = Brand::query()->firstWhere('slug', 'rush');
    expect($brand)->not->toBeNull()
        ->and($brand->domains()->value('host'))->toBe('rush.localhost')
        ->and(Feature::for($brand)->active('commerce:shop'))->toBeTrue()
        ->and(Feature::for($brand)->active('commerce:waiting-room'))->toBeTrue();

    $type = RegistrationType::withoutBrandScope()->where('brand_id', $brand->getKey())->firstWhere('name', 'Rush ticket');
    expect($type)->not->toBeNull();

    $quota = Quota::withoutBrandScope()->where('brand_id', $brand->getKey())->firstWhere('name', 'Rush capacity');
    expect($quota)->not->toBeNull()
        ->and($quota->capacity)->toBe(300)
        ->and($type->quotas()->whereKey($quota->getKey())->exists())->toBeTrue();

    $product = Product::withoutBrandScope()->where('brand_id', $brand->getKey())->firstWhere('slug', 'rush-shirt');
    expect($product)->not->toBeNull()
        ->and($product->variants()->sole()->stock_capacity)->toBe(200);

    expect(SettlementAccount::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('provider', SettlementAccount::PROVIDER_NULL)
        ->exists())->toBeTrue();

    expect(User::query()->where('email', 'like', 'rush+%@konventix.test')->count())->toBe(1200)
        ->and(User::query()->firstWhere('email', 'rush+1@konventix.test')->email_verified_at)->not->toBeNull();

    // Re-running must top up nothing further and duplicate nothing.
    $this->artisan('konventix:seed', ['--rush' => true])->assertSuccessful();

    expect(User::query()->where('email', 'like', 'rush+%@konventix.test')->count())->toBe(1200)
        ->and(RegistrationType::withoutBrandScope()->where('brand_id', $brand->getKey())->where('name', 'Rush ticket')->count())->toBe(1)
        ->and(Product::withoutBrandScope()->where('brand_id', $brand->getKey())->where('slug', 'rush-shirt')->count())->toBe(1);
});
