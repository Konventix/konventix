<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Konventix\Commerce\Actions\SaveProduct;
use Konventix\Commerce\Data\SaveProductData;
use Konventix\Commerce\Enums\PriceDisplay as CommercePriceDisplay;
use Konventix\Commerce\Enums\TaxClass as CommerceTaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Models\WaitingRoomSettings;
use Konventix\Compliance\Actions\CreatePolicy;
use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Event\Models\Event;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\UpdateBrandFeatures;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

/**
 * The rush fixture (ADR-0047 point 6, task 14 of the second build order's
 * tranche 3): one brand, one event, one capacity-capped instant-settlement
 * ticket, one shop item and 1,200 member accounts to arrive against them —
 * exactly what `tests/Load/ticket-rush.js` needs to run a k6 rush and what
 * `tests/Load/RushResultTest.php` reads back afterwards.
 *
 * `EVENT_ID`/`TYPE_ID` are fixed ULIDs rather than the usual factory-minted
 * ones: `HasUlids` only mints an id when the attribute is still empty, so
 * setting one before `create()` pins it. Every other seeder in this
 * directory can afford to let ids fall wherever the factory puts them
 * because nothing outside the database ever needs to name them again — the
 * k6 script is the exception. It builds `/events/{event}/register/{type}`
 * from a literal string baked in at write time (there is no page it can
 * reach *before* a waiting-room ticket to scrape the real ids off of), so
 * this seeder and that script share the same two constants by copy, not by
 * import — PHP and JavaScript have no way to share one.
 *
 * Idempotent throughout: every write below is guarded on the row (or, for
 * the 1,200 members, the email) already existing, the same discipline
 * `DemoCommerceSeeder`/`DemoRegistrationSeeder` use.
 */
final class DemoRushSeeder
{
    /** Copied into `tests/Load/ticket-rush.js` — see class docblock. */
    public const string EVENT_ID = '01M1206XWZPSXJPXXBNJ9ZYJC0';

    /** Copied into `tests/Load/ticket-rush.js` and `tests/Load/RushResultTest.php`. */
    public const string TYPE_ID = '01M1206XWZPSXJPXXBNJ9ZYJC1';

    public const int QUOTA_CAPACITY = 300;

    public const int STOCK_CAPACITY = 200;

    public const int MEMBER_COUNT = 1200;

    public const string BRAND_SLUG = 'rush';

    public const string BRAND_DOMAIN = 'rush.localhost';

    public const string PRODUCT_SLUG = 'rush-shirt';

    public function __construct(
        private readonly UpdateBrandFeatures $updateFeatures,
        private readonly SaveProduct $saveProduct,
        private readonly CreatePolicy $createPolicy,
        private readonly DraftPolicyVersion $draftVersion,
        private readonly PublishPolicyVersion $publishVersion,
        private readonly GrantConsent $grantConsent,
    ) {}

    /**
     * `$brand`/`$operator` are the caller's (`SeedDemoDataCommand`'s
     * `inBrand()`) — the brand context is already pinned to `$brand` when
     * this runs, exactly like `DemoCommerceSeeder::seedShop()`.
     */
    public function brand(?Operator $operator): Brand
    {
        $brand = Brand::query()->firstOrNew(['slug' => self::BRAND_SLUG]);

        if (! $brand->exists) {
            $brand->fill(['name' => 'Rush', 'theme_tokens' => []]);
            $brand->operator()->associate($operator);
            $brand->save();
        }

        $brand->domains()->firstOrCreate(
            ['host' => self::BRAND_DOMAIN],
            ['is_primary' => true],
        );

        return $brand;
    }

    public function handle(Brand $brand): void
    {
        $this->updateFeatures->handle($brand, [
            'commerce:shop' => true,
            'commerce:waiting-room' => true,
        ]);

        $this->seedWaitingRoomSettings($brand);
        $event = $this->seedEvent();
        $this->seedRegistrationType($event);
        $this->seedProduct($brand, $event);
        $this->seedSettlementAccount();
        $policyVersion = $this->seedPolicy();
        $this->seedMembers($brand, $policyVersion);
    }

    /**
     * `admit_per_interval = 100` (the brief's own number): a thousand
     * arrivals drain inside two minutes at `WaitingRoomTick`'s twelve
     * batches-per-minute cadence.
     */
    private function seedWaitingRoomSettings(Brand $brand): void
    {
        $settings = WaitingRoomSettings::forBrand($brand->getKey());
        $settings->admit_per_interval = 100;
        $settings->save();
    }

    private function seedEvent(): Event
    {
        $existing = Event::query()->whereKey(self::EVENT_ID)->first();

        if ($existing !== null) {
            return $existing;
        }

        return Event::factory()->published()->create([
            'id' => self::EVENT_ID,
            'name' => 'Rush Night',
            'slug' => 'rush-night',
        ]);
    }

    /**
     * Instant settlement + `on_payment` confirmation: money moves the
     * moment the form submits (`SubmitRegistration` requests settlement at
     * submission for exactly this combination), landing the ticket in the
     * registrant's cart (`CreateOrderOnSettlementRequested`, since
     * `commerce:shop` is on) rather than waiting on a direct order. Actual
     * confirmation stays deferred — `ConfirmationGate::OnPayment` is TODO.md
     * Phase 2 backlog — which is fine here: the rush proves the door and the
     * money path hold under load, not the confirmation state machine.
     */
    private function seedRegistrationType(Event $event): void
    {
        $existing = RegistrationType::query()->whereKey(self::TYPE_ID)->first();

        if ($existing !== null) {
            return;
        }

        $type = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->settlement(RegistrationSettlement::Instant)
            ->confirmationGate(ConfirmationGate::OnPayment)
            ->priced(unitAmountNet: '25.00')
            ->create(['id' => self::TYPE_ID, 'name' => 'Rush ticket', 'terms_days' => 14]);

        $quota = Quota::factory()
            ->withCapacity(self::QUOTA_CAPACITY)
            ->create(['name' => 'Rush capacity']);

        $type->quotas()->attach($quota);
    }

    /**
     * One implicit variant at `stockCapacity: 200` (the same shape
     * `DemoCommerceSeeder::seedShop()` uses for its sold-out poster) — no
     * pinned id: `SaveProduct` only reuses a variant's id across saves, it
     * never accepts one for a fresh row, so `ticket-rush.js` reads it off
     * `/shop/rush-shirt`'s own page props once a VU is admitted there.
     */
    private function seedProduct(Brand $brand, Event $event): void
    {
        if (Product::query()->where('slug', self::PRODUCT_SLUG)->exists()) {
            return;
        }

        $this->saveProduct->handle($brand, null, new SaveProductData(
            name: 'Rush shirt',
            slug: self::PRODUCT_SLUG,
            description: 'The shirt everyone in the queue was also trying to buy.',
            eventId: $event->getKey(),
            imageMediaId: null,
            taxClass: CommerceTaxClass::Standard,
            unitAmountNet: '15.00',
            priceDisplay: CommercePriceDisplay::Gross,
            published: true,
            stockCapacity: self::STOCK_CAPACITY,
            variants: [],
        ));
    }

    /**
     * `PROVIDER_NULL`, connected: `PlaceOrderFromCart` "starts" a checkout
     * through it without a real PSP behind it —
     * `Konventix\Commerce\Settlement\LocalHostedCheckoutAdapter`
     * (`CommerceServiceProvider`) is what makes that `null` provider offer a
     * hosted checkout at all, and it only binds in `local`/`testing`.
     */
    private function seedSettlementAccount(): void
    {
        if (SettlementAccount::query()->where('provider', SettlementAccount::PROVIDER_NULL)->exists()) {
            return;
        }

        SettlementAccount::factory()
            ->provider(SettlementAccount::PROVIDER_NULL)
            ->create(['label' => 'Rush checkout (no PSP)']);
    }

    /**
     * One required policy, published, so `require-consent` — which the
     * public registration route applies — has something to check every
     * member against.
     */
    private function seedPolicy(): PolicyVersion
    {
        $policy = Policy::query()->firstWhere('slug', 'rush-terms');

        if ($policy === null) {
            /** @var Brand $brand */
            $brand = Brand::query()->where('slug', self::BRAND_SLUG)->firstOrFail();
            $policy = ($this->createPolicy)($brand->getKey(), new PolicyData(name: 'Rush Terms', slug: 'rush-terms'));
        }

        $version = PolicyVersion::query()
            ->where('policy_id', $policy->getKey())
            ->where('version', 'v1.0')
            ->first();

        if ($version !== null) {
            return $version;
        }

        $version = ($this->draftVersion)($policy, new PolicyVersionData(
            version: 'v1.0',
            content: "Rush Terms\n\nArrive, queue, buy a ticket. That's it.",
            requiresConsent: true,
        ));

        return ($this->publishVersion)($version, now()->subDay());
    }

    /**
     * `rush+{n}@konventix.test` / `password` (the `UserFactory` default
     * hash), verified email, consented — everything `SubmitRegistration`'s
     * `require-consent` gate and Fortify's login need. Guarded per email
     * rather than by a single count check: a run that died partway through
     * 1,200 creates must top up the remainder, not skip wholesale because
     * *some* rush members already exist.
     */
    private function seedMembers(Brand $brand, PolicyVersion $policyVersion): void
    {
        $existingEmails = User::query()
            ->where('email', 'like', 'rush+%@konventix.test')
            ->pluck('email')
            ->all();

        for ($n = 1; $n <= self::MEMBER_COUNT; $n++) {
            $email = "rush+{$n}@konventix.test";

            if (in_array($email, $existingEmails, true)) {
                continue;
            }

            $user = User::factory()->create([
                'name' => "Rush Arrival {$n}",
                'email' => $email,
            ]);

            BrandMembership::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                ['first_seen_at' => now()],
            );

            event(new UserRegistered(
                userId: $user->getKey(),
                brandId: $brand->getKey(),
                email: $user->email,
                displayName: $user->name,
                locale: $user->locale,
                registeredAt: $user->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ));

            ($this->grantConsent)($user->getKey(), $policyVersion, 'registration_form');
        }
    }
}
