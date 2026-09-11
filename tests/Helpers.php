<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Konventix\Event\Models\Event;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;

/**
 * Helpers the root suite's seed tests share. They live here rather than in one
 * of those files because paratest hands each worker a subset of the *files*:
 * a function declared inside SeedDemoDataCommandTest.php does not exist in the
 * worker running SeedDemoRushCommandTest.php. tests/Pest.php loads this in
 * every process (.ai/rules/tests.md).
 */
function mediaDiskNameForTest(): string
{
    return (string) config('filesystems.media_disk', 'media');
}

/**
 * Runs the seed at demo-sized-but-fast dimensions. The interactive path is
 * used because `--default` seeds 3 brands with 100 users each; the numbers
 * are the smallest that still exercise every slice (Competition needs eight
 * eligible players, the waitlist needs the Late bird quota to run out).
 *
 * Helper name prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function seedDemoDataForTest(object $test, int $registrations = 12, int $events = 1, int $brands = 1): void
{
    $test->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', (string) $brands)
        ->expectsQuestion('How many users per brand?', '20')
        ->expectsQuestion('How many events per brand?', (string) $events)
        ->expectsQuestion('How many registrations per event?', (string) $registrations)
        ->assertSuccessful();
}

/**
 * The base TestCase creates a factory brand answering on localhost;
 * BrandSeeder wants that host for the dev brand (host is globally unique),
 * so release it before running the seed.
 */
function releaseLocalhostForTest(): void
{
    BrandDomain::query()->where('host', 'localhost')->delete();
}

function seededDemoBrandForTest(): Brand
{
    return Brand::query()->firstWhere('slug', 'sxlan');
}

function seededDemoFairBrandForTest(): Brand
{
    return Brand::query()->firstWhere('slug', 'xpertise');
}

/**
 * @return Builder<Event>
 */
function seededDemoEventsForTest(Brand $brand)
{
    return Event::withoutBrandScope()->where('brand_id', $brand->getKey());
}
