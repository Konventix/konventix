<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Konventix\Admission\Actions\ClaimAdmission;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Content\Models\ContentEntry;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\EventSummary as RegistrationEventSummary;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;
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

        // Monitoring, not a screen: outside the `web` group so it answers on
        // any host, and gated on a shared secret rather than a session.
        'health.json' => 'monitoring endpoint behind a shared secret',
        'prometheus.default' => 'monitoring endpoint behind a shared secret',

        // Reached from an emailed link and nowhere else: both carry a
        // one-time credential in the URL that seeded data cannot produce.
        // Their own tests mint one.
        'password.reset' => 'needs a password-reset token',
        'verification.verify' => 'needs a signed verification link',
        'admission.claim.show' => 'needs a signed claim link (#292 §4.3); the same URL the POST takes',

        // Same shape: konventix:seed never posts to the public data-request
        // form, so no `DataSubjectRequestVerification` row — and therefore no
        // valid token — exists for this route's `{token}` to point at
        // (ADR-0050 §4). PublicDataRequestTest mints one.
        'data-requests.public.verify' => 'needs a mailed verification token',

        // Same shape again: the token only exists once
        // `NotifySubjectOfRequestOutcome::handleExportCompleted` mails an
        // account-less subject an archive link (ADR-0050 §9), which
        // `konventix:seed` never triggers — no export request is ever
        // fulfilled for a seeded person, so no `archive_token_hash` exists
        // for this route's `{token}` to point at. `AccountLessArchiveTest`
        // mints one directly.
        'data-requests.public.download' => 'needs a mailed archive token',

        // Same shape: the signature *is* the authorization, minted during a
        // render that established ownership (see MyInvoiceDocumentController).
        // A URL built from route parameters alone cannot carry one.
        // `RegistrationSettlementSlotTest` follows a real minted link.
        'commerce.my.invoice.document' => 'needs a signed download link',
        // Same reason as above (see MyCreditNoteDocumentController).
        'commerce.my.credit-note.document' => 'needs a signed download link',

        // `konventix:seed` never issues a refund, so neither archetype has a
        // credit note for this route's `{creditNote}` parameter to point
        // at — a genuine seed gap (ADR-0048 point 2), not a route that
        // cannot be a screen.
        'admin.credit-notes.document' => 'no credit note in seeded data — konventix:seed does not exercise a refund',

        // The demo seed sends no mail at all — konventix:seed never calls
        // SendMessage — so no MessageRecord exists for this route's
        // `{message}` parameter to point at. `admin.messages.index` still
        // walks; only the detail screen needs a row.
        'admin.messages.show' => 'no message record after seeding — konventix:seed sends no mail',

        // The order-addressed pay page (GitLab #5) authorizes on the order's
        // own buyer and 404s for anyone else — unlike `commerce.pay.show`,
        // where knowing the registration ULID is the authorization. The walk
        // signs in as one member chosen for their registration and their data
        // request, and konventix:seed leaves that person owning no order, so
        // there is no URL this walk could build that the controller would
        // serve. A genuine seed gap, not a route that cannot be a screen —
        // `OrderRepayPageTest` walks the page itself, both arms of the
        // ownership check included.
        'commerce.pay.order.show' => 'no order owned by the walking member after seeding',
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
    return DataSubjectRequest::query()
        ->where('filed_on_brand_id', $brand->getKey())
        ->whereNotNull('archive_path')
        ->whereNotNull('archive_disk')
        ->where(fn (Builder $query) => $query->whereNull('archive_expires_at')->orWhere('archive_expires_at', '>', now()));
}

function dryRunParameters(RoutingRoute $route, Brand $brand, ?User $member = null): ?array
{
    $name = (string) $route->getName();

    // The checkout page authorizes on the order's payer since GitLab #185 —
    // knowing the registration ULID is no longer enough — so this may not be
    // "any registration of this brand that produced an order" either. It has
    // to be one the walking member is the payer of, or the page 404s exactly
    // as it does for a stranger. `DemoComplianceSeeder` prefers a payer when
    // it picks the subject this walk signs in as, so such a registration
    // exists for both archetypes.
    if ($name === 'commerce.pay.show' && $member !== null) {
        $own = Order::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('settles_type', 'registration')
            ->where('payer_user_id', $member->getKey())
            ->first();

        $registration = $own === null ? null : Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->whereKey($own->settles_id)
            ->first();

        return $registration === null ? null : ['registration' => $registration];
    }

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

    // The seat picker and its availability poll both authorize on the
    // claim's registrant (`FindSeatClaimForRegistrant`) and 404 for anyone
    // else — the ownership rule working exactly as designed, the same shape
    // as `registration.mine.show` above. It is correct behaviour, not a
    // screen this walk can skip past: `DemoResourcesSeeder::seedSeatClaims()`
    // gives every confirmed registrant a genuine, unplaced claim for exactly
    // this reason, so the walking member's own registration resolves here.
    if (($name === 'resources.seating.pick' || $name === 'resources.seating.availability') && $member !== null) {
        $claim = ResourceClaim::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('registrant_user_id', $member->getKey())
            ->where('state', '!=', ClaimState::Released)
            ->orderByDesc('created_at')
            ->first();

        $registration = $claim === null ? null : Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->whereKey($claim->registration_id)
            ->first();

        return $registration === null ? null : ['registration' => $registration];
    }

    // The per-ticket picker and its poll (`resources.admission-seat.show`
    // and `.availability`, #292 §6.4) authorise through
    // `FindSeatClaimForAdmission`: the admission must be in Resources' own
    // read model, live, belong to a registration with a live claim, and the
    // caller must be that claim's registrant or the admission's holder —
    // 404 otherwise, by design. The
    // generic `{admission}` binding below picks the member's *held*
    // admission, which is whatever `seedClaimLinks()` had left unclaimed and
    // may be a staff admission with no registration at all. Bind to an
    // admission of the member's own claimed registration instead, the same
    // row `resources.seating.pick` resolves through.
    if (in_array($name, ['resources.admission-seat.show', 'resources.admission-seat.availability'], true) && $member !== null) {
        $claim = ResourceClaim::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('registrant_user_id', $member->getKey())
            ->where('state', '!=', ClaimState::Released)
            ->orderByDesc('created_at')
            ->first();

        $admission = $claim === null ? null : ResourceAdmission::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('registration_id', $claim->registration_id)
            ->where('state', '!=', ResourceAdmission::STATE_REVOKED)
            ->first();

        return $admission === null ? null : ['admission' => $admission->getKey()];
    }

    // The buyer's combined ticket file (`admission.tickets.buyer`, #72):
    // authorised entirely on `admissions.buyer_user_id` naming the
    // signed-in account (ADR-0004 — `TicketDownloadController::buyer()`
    // never reads a Registration row), the same shape commit f8f794d
    // already fixed for `admission.tickets.holder`'s `{admission}`. "Any
    // registration that produced an order" only ever passed this route's
    // ownership check by luck of row order — this binds to a registration
    // the walking member is genuinely the buyer of instead.
    // `DemoCommerceSeeder`'s Supporter type confirms at submission (factory
    // defaults: on_submit, no approval), which issues each Supporter
    // registrant an admission naming them as buyer, and
    // `DemoComplianceSeeder::seedSubjectRequests()` prefers a payer — a
    // Supporter registrant — for the subject this walk signs in as, so this
    // resolves for both archetypes.
    if ($name === 'admission.tickets.buyer' && $member !== null) {
        $admission = Admission::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('buyer_user_id', $member->getKey())
            ->where('state', '!=', AdmissionState::Revoked)
            ->whereNotNull('registration_id')
            ->first();

        $registration = $admission === null ? null : Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->whereKey($admission->registration_id)
            ->first();

        return $registration === null ? null : ['registration' => $registration];
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

    // Community's `internal`-by-default visibility gate (ADR-0052) means the
    // walking viewer must themselves be a `CommunityMember` of this brand or
    // the wall 404s — so this points at the walking member's OWN profile
    // when there is one (a self-view always passes the gate), else at any
    // `CommunityMember` on this brand (which only resolves once the walker
    // is a member too). The console walk passes no `$member` at all, so this
    // never matches there — `community.profile.show` is a public/account
    // screen only.
    if ($name === 'community.profile.show') {
        $username = $member !== null
            ? CommunityProfile::query()->whereKey($member->getKey())->value('username')
            : null;

        $username ??= CommunityProfile::query()
            ->whereIn('id', CommunityMember::withoutBrandScope()->where('brand_id', $brand->getKey())->select('user_id'))
            ->value('username');

        return $username === null ? null : ['username' => $username];
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
            // The attendee list 404s while the event's switch is off
            // (ADR-0064); `DemoEventSeeder` switches it on for every public
            // event, and Registration's summary carries the projected value.
            $parameter === 'slug' && $name === 'registration.attendees.index' => RegistrationEventSummary::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->where('attendee_list_visibility', 'public')
                ->value('slug'),
            // `venue.show` 404s unless the venue belongs to a publicly
            // visible event; the seed assigns each brand's first venue to
            // its public events, and the console's edit screen takes any.
            $parameter === 'venue' => Venue::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->get()
                ->first(fn (Venue $venue): bool => $venue->isPubliclyReachable())
                ?? Venue::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),

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
            // A registration that produced an order, for any route that
            // names one without owning it the way `commerce.pay.show` does
            // (that one is resolved against the walking member's own payer
            // row above, GitLab #185).
            $parameter === 'registration' => Registration::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->whereIn('id', Order::withoutBrandScope()
                    ->where('brand_id', $brand->getKey())
                    ->select('settles_id'))
                ->first(),
            $parameter === 'registrationType' => RegistrationType::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first(),
            // `{admission}` is only ever this route's — `admission.tickets.holder`
            // (the console's badge route resolves it without a `$member`) —
            // and `holder()` 403s unless `holder_user_id` names the signed-in
            // account. Most admissions have no holder at all (a sold
            // admission names nobody until it is claimed), so "any admission
            // of the brand" only ever passed the check by luck of row order.
            // `DemoAdmissionSeeder::seedClaimLinks()` claims one admission
            // for the first seeded member on purpose so a holder's list is
            // never empty; this binds to the walking member's own held
            // admission instead of an arbitrary one.
            $parameter === 'admission' && $member !== null => Admission::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->where('holder_user_id', $member->getKey())
                ->first(),
            $parameter === 'admission' => Admission::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            // The console's plan screens (`admin.plans.edit`/`.download`/
            // `.occupancy`): `DemoResourcesSeeder::seedPlan()` gives every
            // event one, empty but real, so they are genuinely reachable.
            $parameter === 'plan' => Plan::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            // The console's category screen (`admin.resource-types.edit`) —
            // `DemoResourcesSeeder::seedCatalogue()` already seeds these.
            $parameter === 'resourceType' => ResourceType::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            // Always null — konventix:seed sends no mail, so no brand ever
            // has a MessageRecord — which is exactly why the route this
            // parameter belongs to is also listed in dryRunSkippedRoutes().
            $parameter === 'message' => MessageRecord::withoutBrandScope()->where('brand_id', $brand->getKey())->first(),
            // The template editor's routes (Task 8, ADR-0048 decision 4)
            // are keyed by the registered template key, not a seeded row —
            // any real key does, and `commerce.invoice-issued` is always
            // registered.
            $parameter === 'key' => 'commerce.invoice-issued',
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
 * Route names that legitimately redirect rather than answer 200 during the
 * console walk, and why. The template editor's edit screen needs a brand
 * picked in the console shell (`ConsoleSelection`, ADR-0029) — the walk logs
 * in and hits routes directly, never visiting `ConsoleSelectionController`
 * to make a pick, so the session carries no selection and the controller
 * redirects to the index with a toast rather than rendering. That is the
 * screen's own guard working, not a broken route — a plain `<400` still
 * proves it is wired up and reachable.
 *
 * @return list<string>
 */
function dryRunConsoleRedirectAllowed(): array
{
    return ['admin.messages.templates.edit'];
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
        // The holding page (Task 13) sits behind its own feature, off for
        // both seeded archetypes — walking it here would only prove the
        // 404 `EnsureFeatureIsActive` already gives, not a working page.
        'commerce.waiting-room.' => 'commerce:waiting-room',
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

        if (in_array((string) $route->getName(), dryRunConsoleRedirectAllowed(), true)) {
            expect($status)->toBeLessThan(400, "{$route->getName()} ({$url}) answered {$status}");

            continue;
        }

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

    // `admission.tickets.holder`'s `{admission}` (resolved below) authorizes
    // entirely on `holder_user_id` naming the signed-in account, and
    // `DemoAdmissionSeeder::seedClaimLinks()` claims exactly one admission on
    // purpose — for whichever member it seeds first, not necessarily the
    // member chosen above for their registration and their data request.
    // Claiming one here, through the same `ClaimAdmission` action a real
    // claim link redeems, is this test's own fixture rather than a resolver
    // papering over that seed gap: skipping the route instead would mean the
    // holder download is never exercised at all.
    if (! Admission::withoutBrandScope()->where('brand_id', $brand->getKey())->where('holder_user_id', $member->getKey())->exists()) {
        $unclaimed = Admission::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->whereNull('holder_user_id')
            ->whereIn('state', [AdmissionState::Issued, AdmissionState::Assigned])
            ->first();

        if ($unclaimed !== null) {
            $link = app(IssueAdmissionClaimLink::class)->handle($unclaimed);
            app(ClaimAdmission::class)->handle($link->getKey(), $member->getKey());
        }
    }

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
