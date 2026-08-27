<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Konventix\Admission\Models\Admission;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Content\Models\ContentEntry;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Laravel\Pennant\Feature;

/**
 * The dry run (slice 16): SXLan and Xpertise, end to end on seeded data.
 *
 * Every other test in this suite proves one screen against fixtures it built
 * for itself. This one proves that the screens exist *together* — that after
 * `konventix:seed` has run the two archetypes through the real Actions, every
 * page the console and the public site offer actually renders against what
 * came out. That is the difference the definition of done asks for, and it is
 * the class of failure unit-shaped tests never see: a controller that needs a
 * relation the seed does not produce, a page whose props were renamed, a
 * document renderer that only works on the fixture it was written against.
 *
 * The route table is the input, not a hand-kept list, so a screen added
 * without a thought for seeded data fails here the day it lands. Anything
 * genuinely not a screen is skipped by name, with the reason next to it.
 *
 * This does not replace an assistive-technology pass, and it is not a visual
 * check. It answers one question: does it come up.
 */
beforeEach(function (): void {
    Storage::fake((string) config('filesystems.media_disk', 'media'));
});

/**
 * Routes that are not screens, and why. Everything else in the GET table is
 * expected to answer.
 *
 * @return array<string, string>
 */
function dryRunSkippedRoutes(): array
{
    return [
        // Answers a reverse proxy asking whether to mint a certificate, and
        // deliberately lives outside the `web` group (ADR-0001).
        'platform.caddy-ask' => 'proxy endpoint, not a page',

        // Steam's OpenID round trip: redirecting away, and coming back with a
        // signature this test cannot mint (D-IDN-01).
        'social.redirect' => 'hands off to Steam',
        'social.callback' => 'requires a signed Steam response',
        'social.complete' => 'requires a pending social signup in session',

        // The entrance device API (ADR-0033) authenticates with a device
        // token, not a session, and returns a delta feed rather than a page.
        'entrance.delta' => 'device-token API',
        'entrance.signing-keys' => 'device-token API',

        // Comes back from Stripe's hosted onboarding and calls Stripe.
        'admin.settlement-accounts.stripe-return' => 'round trip through Stripe',

        // Monitoring, not a screen: outside the `web` group so it answers on
        // any host, and gated on a shared secret rather than a session.
        'health.json' => 'monitoring endpoint behind a shared secret',

        // Reached from an emailed link and nowhere else: both carry a
        // one-time credential in the URL that seeded data cannot produce.
        // Their own tests mint one.
        'password.reset' => 'needs a password-reset token',
        'verification.verify' => 'needs a signed verification link',

        // Same shape: the signature *is* the authorization, minted during a
        // render that established ownership (see MyInvoiceDocumentController).
        // A URL built from route parameters alone cannot carry one.
        // `RegistrationSettlementSlotTest` follows a real minted link.
        'commerce.my.invoice.document' => 'needs a signed download link',
    ];
}

/**
 * Route-name prefixes owned by a package rather than by us. The
 * observability dashboards mount under the admin host's path (ADR-0029) but
 * are nobody's screens to fix here — `ObservabilityDashboardTest` covers that
 * they are reachable and, more to the point, that they are not reachable
 * without the gate.
 *
 * @return list<string>
 */
function dryRunVendorRoutePrefixes(): array
{
    return ['horizon.', 'pulse', 'telescope', 'debugbar.', 'livewire.', 'storage.'];
}

/**
 * Every named GET route that should answer, in a stable order.
 *
 * @return Collection<int, RoutingRoute>
 */
function dryRunRoutes(): Collection
{
    $skipped = dryRunSkippedRoutes();
    $vendor = dryRunVendorRoutePrefixes();

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        // Unnamed routes are the service worker and the web manifest, which
        // Vite serves from the build rather than the router.
        ->filter(fn (RoutingRoute $route): bool => $route->getName() !== null)
        ->reject(fn (RoutingRoute $route): bool => array_key_exists((string) $route->getName(), $skipped))
        ->reject(fn (RoutingRoute $route): bool => collect($vendor)->contains(
            fn (string $prefix): bool => str_starts_with((string) $route->getName(), $prefix),
        ))
        ->sortBy(fn (RoutingRoute $route): string => (string) $route->getName())
        ->values();
}

/**
 * Runs the seed at the smallest dimensions that still produce both
 * archetypes with something on every screen.
 */
function dryRunSeed(object $test): void
{
    BrandDomain::query()->where('host', 'localhost')->delete();

    $test->artisan('konventix:seed')
        ->expectsQuestion('How many brands (besides the dev brand)?', '2')
        ->expectsQuestion('How many users per brand?', '20')
        ->expectsQuestion('How many events per brand?', '1')
        ->expectsQuestion('How many registrations per event?', '12')
        ->assertSuccessful();
}

/**
 * A seeded model for each route parameter. Returns null when the route needs
 * something this brand has none of — a fair seeds no tournament — which the
 * caller reports as a skip rather than a pass.
 *
 * Models rather than ids, so `route()` resolves each one's own binding field:
 * the public event route binds on `slug`, the console's on the key.
 *
 * @return array<string, mixed>|null
 */
/**
 * Seeded subject requests whose archive can actually be downloaded. Anything
 * else answers 404 on the download route rather than rendering the screen
 * under test.
 *
 * @return Builder<DataSubjectRequest>
 */
function dryRunDownloadableRequests(Brand $brand): Builder
{
    return DataSubjectRequest::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->whereNotNull('archive_path')
        ->whereNotNull('archive_disk')
        ->where(fn (Builder $query) => $query->whereNull('archive_expires_at')->orWhere('archive_expires_at', '>', now()));
}

function dryRunParameters(RoutingRoute $route, Brand $brand, ?User $member = null): ?array
{
    $name = (string) $route->getName();

    // The registrant's own registration: the controller authorizes on
    // ownership and 403s for anyone else, so this may not be "any
    // registration of this brand" the way the console's screens are.
    if ($name === 'registration.mine.show' && $member !== null) {
        $own = Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('registrant_user_id', $member->getKey())
            ->first();

        return $own === null ? null : ['registration' => $own];
    }
    $eventQuery = fn () => Event::withoutBrandScope()->where('brand_id', $brand->getKey());

    // The public form takes both halves of one pair: the type must belong to
    // the event in the URL and be publicly visible, or the controller 404s.
    // Resolving them independently would pick a type from another edition.
    if ($name === 'registration.show') {
        $type = RegistrationType::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('visibility', RegistrationVisibility::Public)
            ->first();

        return $type === null ? null : ['event_ulid' => $type->event_id, 'type' => $type];
    }

    $resolved = [];

    foreach ($route->parameterNames() as $parameter) {
        $value = match (true) {
            // `slug` means a different thing on each route that takes one.
            $parameter === 'slug' && $name === 'content.show' => ContentEntry::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->whereNotNull('published_at')
                ->value('slug'),
            $parameter === 'slug' && $name === 'platform.pages.show' => StaticPage::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->value('slug'),
            $parameter === 'slug' && $name === 'policies.show' => Policy::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->value('slug'),
            // The public shop 404s behind `ensure-feature:commerce:shop`
            // (ADR-0047), so this only resolves for a brand the demo seed
            // switched the feature on for — the LAN archetype.
            $parameter === 'slug' && $name === 'commerce.shop.show' => Product::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->published()
                ->value('slug'),

            $parameter === 'brand' => $brand,
            $parameter === 'event' => $eventQuery()->first(),
            $parameter === 'user' => User::query()->firstWhere('email', 'admin@konventix.test'),
            $parameter === 'role' => Role::query()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'media' => MediaItem::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'page' => StaticPage::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'variant' => 'thumb',
            $parameter === 'entry' => ContentEntry::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'policy' => Policy::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            // The archive has to be there, or the download answers 404
            // rather than rendering the screen under test — and when a
            // member is walking, it has to be *theirs*, because the
            // subject's own route 403s for everyone else.
            $parameter === 'subjectRequest' => dryRunDownloadableRequests($brand)
                ->when($member !== null, fn (Builder $query) => $query->where('subject_id', $member->getKey()))
                ->first(),
            $parameter === 'order' => Order::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'product' => Product::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'invoice' => Invoice::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            $parameter === 'account' => SettlementAccount::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first(),
            // The checkout page is addressed by the registration its order
            // settles, and 404s for one that never produced an order — so it
            // must be pointed at a registration that did.
            $parameter === 'registration' => Registration::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->whereIn('id', Order::withoutBrandScope()
                    ->where('brand_id', $brand->getKey())
                    ->select('settles_id'))
                ->first(),
            $parameter === 'registrationType' => RegistrationType::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first(),
            $parameter === 'admission' => Admission::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        $resolved[$parameter] = $value;
    }

    return $resolved;
}

/**
 * Query parameters a screen needs before it will do anything. Kept apart from
 * the route parameters because these are not part of the URL's shape — they
 * are the form the page was submitted from, and a route that validates them
 * redirects rather than answering.
 *
 * @return array<string, string|int>
 */
function dryRunQueryDefaults(RoutingRoute $route): array
{
    return match ((string) $route->getName()) {
        // The hand-over to the tax advisor is always for one month
        // (ADR-0040); without a period there is nothing to export.
        'admin.bookkeeping.datev', 'admin.bookkeeping.journal' => [
            'year' => now()->year,
            'month' => now()->month,
        ],
        default => [],
    };
}

/**
 * The path a route resolves to for this brand, re-hosted onto `$host` —
 * `route()` builds absolute URLs against the app URL, and which host a
 * request arrives on is the whole point here (ADR-0001, ADR-0029).
 */
function dryRunUrl(RoutingRoute $route, array $parameters, string $host): string
{
    $generated = route((string) $route->getName(), $parameters);

    return 'http://'.$host.(parse_url($generated, PHP_URL_PATH) ?? '/')
        .(($query = parse_url($generated, PHP_URL_QUERY)) !== null ? '?'.$query : '');
}

function dryRunIsConsoleRoute(RoutingRoute $route): bool
{
    return str_starts_with((string) $route->getName(), 'admin.');
}

/**
 * Route-name prefixes gated behind a per-brand feature with no module gate
 * behind it (ADR-0047), and the feature name that gates them.
 *
 * @return array<string, string>
 */
function dryRunFeatureGatedRoutePrefixes(): array
{
    return [
        'commerce.shop.' => 'commerce:shop',
        // The cart (Task 11) sits behind the same feature the shop itself
        // needs — a brand that never switched shopping on has no cart to
        // walk either.
        'commerce.cart.' => 'commerce:shop',
    ];
}

/**
 * Whether a route is reachable *at all* for this brand — distinct from
 * whether the demo seeded something to point its parameters at. The shop
 * 404s behind `commerce:shop`, which the seed switches on only for the LAN
 * archetype (same as Competition's module gate is on only for it): a trade
 * fair simply does not carry a shop, and that is the gate working, not a
 * screen the seed forgot to fill in. Walking the route against a brand with
 * the feature off would only prove the 404 `BrandFeaturesTest` already
 * covers.
 */
function dryRunIsReachableForBrand(RoutingRoute $route, Brand $brand): bool
{
    foreach (dryRunFeatureGatedRoutePrefixes() as $prefix => $feature) {
        if (str_starts_with((string) $route->getName(), $prefix)) {
            return Feature::for($brand)->active($feature);
        }
    }

    return true;
}

/**
 * The operator console, against everything two seeded archetypes produced.
 *
 * One sweep, not two: the console is the operator's, not a brand's — the
 * admin host resolves no brand at all (ADR-0029) — so its screens see both
 * archetypes at once and the parameterised ones are pointed at the LAN.
 */
it('serves every operator console screen on seeded data', function () {
    dryRunSeed($this);

    $brand = Brand::query()->firstWhere('slug', 'sxlan');
    $admin = User::query()->firstWhere('email', 'admin@konventix.test');

    $routes = dryRunRoutes()->filter(dryRunIsConsoleRoute(...));

    // A console that lost its route group would otherwise pass this test by
    // having nothing to check. The floor is well under the current count —
    // it is here to catch a filter that stopped matching, not to be kept in
    // step with every new screen.
    expect($routes->count())->toBeGreaterThanOrEqual(40);

    $unresolved = [];

    foreach ($routes as $route) {
        $parameters = dryRunParameters($route, $brand);

        if ($parameters === null) {
            $unresolved[] = $route->getName();

            continue;
        }

        $url = dryRunUrl($route, [...$parameters, ...dryRunQueryDefaults($route)], 'admin.localhost');

        // getStatusCode(), not status(): the document and export routes
        // answer with a StreamedResponse, which TestResponse forwards to.
        $status = $this->actingAs($admin)->get($url)->getStatusCode();

        // 200 exactly, not merely "not an error": the operator admin holds
        // every capability, so a redirect here is an authorization refusal
        // wearing a success code, and that is precisely the failure a
        // permission rename causes.
        expect($status)->toBe(200, "{$route->getName()} ({$url}) answered {$status}");
    }

    // Every console screen must have had seeded data to render: a screen the
    // demo cannot fill is a screen the dry run never actually tried.
    expect($unresolved)->toBe([]);
});

/**
 * The public site and the account area, once per archetype — these screens
 * are brand-scoped, and a LAN and a fair do not carry the same things.
 */
it('serves every public and account screen for both archetypes', function (string $slug) {
    dryRunSeed($this);

    $brand = Brand::query()->firstWhere('slug', $slug);
    $host = $brand->domains()->value('host');
    // One member walks every account screen, so they have to own the rows
    // those screens are about: their own registration, and the data-subject
    // request whose archive `data-requests.download` streams. Both routes
    // authorize on ownership and 403 for anybody else, and picking the
    // member and the rows with separate unordered `first()` calls only ever
    // agreed about which person they meant by luck of row order.
    //
    // `firstOrFail` rather than a fallback: a seed that produces no such
    // person means these screens go unwalked, and a dry run that quietly
    // skips them is worth less than one that says so.
    $member = User::query()
        ->whereIn('id', BrandMembership::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->select('user_id'))
        ->whereIn('id', Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->select('registrant_user_id'))
        ->whereIn('id', dryRunDownloadableRequests($brand)->select('subject_id'))
        ->firstOrFail();

    $routes = dryRunRoutes()
        ->reject(dryRunIsConsoleRoute(...))
        ->filter(fn (RoutingRoute $route): bool => dryRunIsReachableForBrand($route, $brand));

    expect($routes)->not->toBeEmpty();

    $unresolved = [];

    foreach ($routes as $route) {
        $parameters = dryRunParameters($route, $brand, $member);

        if ($parameters === null) {
            $unresolved[] = $route->getName();

            continue;
        }

        $url = dryRunUrl($route, [...$parameters, ...dryRunQueryDefaults($route)], $host);

        $status = $this->actingAs($member)->get($url)->getStatusCode();

        // Not pinned to 200 the way the console is: a signed-in visitor is
        // *meant* to be redirected away from the login and registration
        // forms.
        expect($status)->toBeLessThan(400, "{$route->getName()} ({$url}) answered {$status} for {$slug}");
    }

    expect($unresolved)->toBe([]);
})->with(['sxlan', 'xpertise']);
