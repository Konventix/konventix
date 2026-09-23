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
use Konventix\Agenda\Models\EventSummary as AgendaEventSummary;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary as CompetitionEventSummary;
use Konventix\Competition\Models\Stage;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Content\Models\ContentEntry;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EventSummary as RegistrationEventSummary;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;
use Konventix\Support\Models\SupportRequest;
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

        // Same shape once more: a guest's own report is reached only through
        // the 30-day signed link in their receipt (#307, design §8.2), and
        // `konventix:seed` files no report to mail one for. Without a
        // signature the route deliberately answers the link-expired page
        // rather than the screen this walk is checking, which would pass
        // while proving nothing. `GuestAccessTest` mints a link for both
        // sides of that.
        'support.requests.show' => 'needs a signed 30-day reporter link',

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

        // Step two of the same pair (ADR-0066 §6.3, D-CPL-07) — the
        // confirmation pane behind `/pay/order/{order}`. Same seed gap and
        // the same ownership check: there is no order this walk's member
        // owns, so there is no URL the controller would serve.
        // `CheckoutContractConsentGateTest` walks both steps.
        'commerce.pay.order.confirm' => 'no order owned by the walking member after seeding',

        // The event shoutbox (design §8, D-COM-03) is for a member admitted
        // to the running event, and both screens answer 404 to anyone else
        // by design — not a redirect, because the room is derived from the
        // viewer's own admission and there is no room to send them to.
        // `konventix:seed` scans nobody in at a door, so the walking member
        // has no running event and there is no URL this walk could build
        // that the controller would serve. `ShoutboxPageTest` walks both,
        // for an admitted participant and for a stranger.
        'community.shoutbox.show' => 'room page for an admitted participant; 404s for anyone else by design',
        'community.shoutbox.messages' => 'JSON catch-up feed for an admitted participant; 404s for anyone else by design',
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
    // Step two of the same path (ADR-0066 §6.3) answers for the same order
    // and under the same ownership rule, so it takes the same binder. With
    // no provider on record it redirects back to step one, which is a page
    // and not an error.
    if (in_array($name, ['commerce.pay.show', 'commerce.pay.confirm'], true) && $member !== null) {
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

    // The public tournament pages (Competition design §10). Every one of
    // them is addressed by the EVENT's slug — resolved against Competition's
    // own `competition_event_summaries`, which 404s an event that is not
    // public — and the competition's SLUG, not its ULID, so the generic
    // `competition` case below (a model, hence a ULID in the URL) would put
    // a ULID where the controller reads a slug and walk a 404. A match and a
    // team are rows of that same competition. `DemoCompetitionSeeder` gives
    // every LAN event a drawn Main Cup, so a public event of the
    // brand always has one.
    if (in_array($name, ['competition.event.tournaments', 'competition.page', 'competition.match.page', 'competition.team.page'], true)) {
        return dryRunTournamentParameters($name, $brand);
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
            // The public programme resolves its slug against Agenda's own
            // read model (ADR-0004 — the route never sees Event's model) and
            // 404s for an event whose lifecycle state is not public, exactly
            // as `EventSummary::hasPublicProgramme()` decides. A published
            // event of the brand is what makes this page a page; an empty
            // programme still renders, because having planned nothing yet is
            // not an error.
            $parameter === 'slug' && $name === 'agenda.programme' => AgendaEventSummary::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->whereIn('state', ['published', 'running', 'concluded'])
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
            // Scoped to the walking member when there is one: `/my/orders/{order}`
            // and `/pay/order/{order}` 404 for everyone but the payer (#185),
            // and a bare `first()` on a brand with several seeded orders
            // picked somebody else's whenever Postgres felt like returning
            // it first — a walk that passed or failed by heap order.
            $parameter === 'order' => Order::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->when($member !== null, fn (Builder $query) => $query->where('payer_user_id', $member->getKey()))
                ->first(),
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
            // The entry-condition editor (ADR-0066 §9.1). `konventix:seed`
            // defines no condition — a reduced fare is an operator's own
            // product decision, not something a demo should invent for two
            // archetypes — so this walk mints one itself, the way the public
            // walk claims an admission below rather than skipping the
            // holder's download. Skipping instead would mean the editor is
            // never walked at all.
            $parameter === 'entryCondition' => EntryCondition::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first()
                ?? app(BrandContext::class)->withBrand(
                    $brand,
                    fn (): EntryCondition => EntryCondition::factory()->student()->create(),
                ),
            // The support console (design §9, #306). `konventix:seed` files
            // no report — a demo that invented complaints from its own
            // archetypes would be inventing the one kind of data an
            // operator reads as real — so this walk mints one, the way the
            // entry-condition editor above mints a condition. Deliberately
            // *not* `withoutBrandScope()`: `SupportRequest` carries no
            // `BelongsToBrand` at all (ADR-0050 §1), which is what lets the
            // inbox span brands on the admin host in the first place.
            //
            // The reporter's own page (#307) shares that parameter name and
            // needs a stricter row: `/my/support-requests/{supportRequest}`
            // 404s for everyone but the account that filed it, so a report
            // of the brand picked by heap order would fail the walk for the
            // walking member — the same trap `{order}` and `{admission}`
            // above already carry a `$member` clause for. Filed on the
            // brand *and* by that member, minted when neither exists.
            $parameter === 'supportRequest' && $member !== null && str_starts_with($name, 'support.requests.mine.') => SupportRequest::query()
                ->where('filed_on_brand_id', $brand->getKey())
                ->where('user_id', $member->getKey())
                ->first()
                ?? SupportRequest::factory()
                    ->filedOnBrand((string) $brand->getKey())
                    ->byUser((string) $member->getKey())
                    ->create(),
            $parameter === 'supportRequest' => SupportRequest::query()
                ->where('filed_on_brand_id', $brand->getKey())
                ->first()
                ?? SupportRequest::factory()->filedOnBrand((string) $brand->getKey())->create(),
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
            // The competition console (design §9, §5.4, #296/#297).
            // `DemoCompetitionSeeder` gives the LAN archetype two
            // disciplines and a played-out Main Cup, so the competition
            // form, the entrants panel and the discipline editor all have a
            // real row of that brand to point at. The trade-fair archetype
            // carries no competitions at all — the module gate working, not
            // a gap — and this walk runs the console against the LAN.
            $parameter === 'competition' => Competition::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first(),
            $parameter === 'discipline' => Discipline::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->first(),
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
 * The four public tournament routes' parameters, all from one competition
 * whose event's tournament pages are public and which the public may see,
 * or null when the brand has none (the trade-fair archetype runs no
 * tournaments, and its module is off, so it never walks these routes).
 *
 * @return array<string, string>|null
 */
function dryRunTournamentParameters(string $name, Brand $brand): ?array
{
    $events = CompetitionEventSummary::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->whereIn('state', ['published', 'running', 'concluded'])
        ->pluck('slug', 'event_id');

    $competition = Competition::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->whereIn('event_id', $events->keys())
        ->where('status', '!=', 'draft')
        ->whereIn('id', Stage::withoutBrandScope()->whereNotNull('generated_at')->select('competition_id'))
        ->orderBy('created_at')
        ->first();

    if ($competition === null) {
        return null;
    }

    $parameters = ['slug' => (string) $events[$competition->event_id]];

    if ($name === 'competition.event.tournaments') {
        return $parameters;
    }

    $parameters['competition'] = $competition->slug;

    if ($name === 'competition.match.page') {
        $match = CompetitionMatch::withoutBrandScope()
            ->where('competition_id', $competition->getKey())
            ->orderBy('round')
            ->orderBy('position')
            ->value('id');

        return $match === null ? null : [...$parameters, 'match' => (string) $match];
    }

    if ($name === 'competition.team.page') {
        $team = Entrant::withoutBrandScope()
            ->where('competition_id', $competition->getKey())
            ->value('competition_team_id');

        return $team === null ? null : [...$parameters, 'team' => (string) $team];
    }

    return $parameters;
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
        // Competition is a gateable module (ADR-0003), and `konventix:seed`
        // switches it on for the LAN archetype only — a trade fair runs no
        // tournaments. Its participant pages 404 behind
        // `ensure-module:competition` for the fair, which is the gate
        // working; walking them there would only prove the 404
        // `EnsureModuleIsActive` already gives. The feature name is the one
        // `ModuleGate::featureName()` builds.
        'competition.' => 'module:competition',
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
