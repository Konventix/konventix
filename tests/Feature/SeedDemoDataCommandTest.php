<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Models\RegistrationType;

// The seed writes real files through StoreMediaUpload; without a fake disk
// every run of this suite litters storage/app/public/media.
beforeEach(function (): void {
    Storage::fake('media');
});

/**
 * The demo seed's commerce slice runs through the real settlement chain
 * (ADR-0006): a priced Supporter registration must yield an order and an
 * invoice, and the alternating manual payment must leave both paid and
 * pending orders behind. Small dimensions keep the run fast; the interactive
 * path is used because `--default` seeds 3 brands with 100 users each.
 */
it('seeds orders, invoices and payments through the settlement chain', function () {
    // The base TestCase creates a factory brand answering on localhost;
    // BrandSeeder wants that host for the dev brand (host is globally
    // unique), so release it before running the seed.
    BrandDomain::query()->where('host', 'localhost')->delete();

    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '6')
        ->assertSuccessful();

    $brand = Brand::query()->firstWhere('slug', 'sxlan');
    expect($brand)->not->toBeNull()
        ->and($brand->operator_id)->not->toBeNull();

    // 6 registrations / 3 = 2 supporters: index 0 paid, index 1 pending.
    $orders = Order::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($orders)->toHaveCount(2)
        ->and($orders->where('status', OrderStatus::Paid))->toHaveCount(1)
        ->and($orders->where('status', OrderStatus::Pending))->toHaveCount(1);

    $invoices = Invoice::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($invoices)->toHaveCount(2)
        ->and($invoices->first()->invoice_number)->toStartWith('SXLAN-');

    // Re-running must not double-submit: commerce seeding is idempotent on
    // the Supporter type's existence, like events are on their slug.
    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '6')
        ->assertSuccessful();

    expect(Order::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(2)
        ->and(RegistrationType::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('name', 'Supporter')
            ->count())->toBe(1);
});

/**
 * The media slice (ADR-0035): placeholder artwork goes through the real
 * StoreMediaUpload action, so the demo library has stored files with `thumb`
 * variants, and the entities that reference them — the home page hero and the
 * event's badge template — leave usage references behind, which is what makes
 * the console's delete guard demonstrable.
 */
it('seeds the media library with referenced and unreferenced files', function () {
    BrandDomain::query()->where('host', 'localhost')->delete();

    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '2')
        ->assertSuccessful();

    $brand = Brand::query()->firstWhere('slug', 'sxlan');

    $media = MediaItem::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->get()
        ->keyBy('original_filename');

    expect($media)->toHaveCount(5)
        ->and($media->keys()->all())->toContain('sxlan-logo.png', 'sxlan-hero.jpg', 'sxlan-badge-background.png');

    // Without the symlink the seeded URLs hit the local disk's signed
    // storage/{path} route and 403 instead of serving the image.
    expect(file_exists(public_path('storage')))->toBeTrue();

    // Uploaded through the real action: files land on the disk and the
    // thumbnail generator produced a variant for every oversized image.
    $media->each(function (MediaItem $item): void {
        Storage::disk('media')->assertExists($item->path);
        expect($item->variants)->toHaveKey('thumb');
        Storage::disk('media')->assertExists($item->variants['thumb']);
    });

    // The badge template references its assets by ULID, never a path.
    $template = BadgeTemplate::withoutBrandScope()->where('brand_id', $brand->getKey())->sole();
    expect($template->layout->logoMediaId)->toBe($media['sxlan-logo.png']->getKey())
        ->and($template->layout->backgroundMediaId)->toBe($media['sxlan-badge-background.png']->getKey());

    // The home page hero references the seeded image by ULID (ADR-0043),
    // never by a resolved URL.
    $home = StaticPage::withoutBrandScope()->where('brand_id', $brand->getKey())->firstWhere('slug', 'home');
    expect($home->blocks[0]['data']['imageMediaId'])->toBe($media['sxlan-hero.jpg']->getKey());

    $usages = MediaUsage::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($usages)->toHaveCount(3)
        ->and($usages->where('module', 'admission'))->toHaveCount(2)
        ->and($usages->firstWhere('module', 'platform')->entity_id)->toBe($home->getKey())
        // The sponsor logos stay unreferenced, so the console has deletable
        // files next to guarded ones.
        ->and($usages->pluck('media_item_id'))->not->toContain($media['sponsor-northwind.png']->getKey());

    // Re-running tops up rather than duplicating: media is idempotent per
    // filename, the badge template on the event's fallback row.
    $this->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '1')
        ->expectsQuestion('How many users per brand?', '4')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '2')
        ->assertSuccessful();

    expect(MediaItem::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(5)
        ->and(BadgeTemplate::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(1)
        ->and(MediaUsage::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(3);
});
