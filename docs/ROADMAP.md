# Roadmap

## Phase 0 — before the first commit (blockers)

Cleared.

- [x] IP position settled (ADR-0011). No agreement with the Verein was needed:
      the predecessor (Lan-Software / LanCore) already ran under Markus Kohn's
      own name, so sole copyright was never in question.
- [x] konventix.de, konventix.eu and the GitHub organisation registered and held
      by Markus Kohn. Trademark search (DPMAregister, TMview, EUIPO eSearch)
      still outstanding before public launch — note the phonetically similar
      "conventic GmbH".
- [x] LICENSE (AGPL-3.0-or-later) and this docs set committed.

## Phase 1 — PoC (riskiest unknowns only; no payment, no tournament logic)

Proven:
- [x] Multi-domain brand resolution: ResolveBrand fronts the `web` group
      (every request runs in a brand or 404s), dev web role runs FrankenPHP
      via Octane.

      **TLS is out of scope (decided 2026-08-19).** Konventix runs behind a
      reverse proxy that terminates TLS and is not part of this application,
      so the production Caddyfile wiring `on_demand_tls` to `/caddy/ask` is
      no longer owed. Certificate issuance for a newly added brand domain
      becomes that proxy's business.

      The `/caddy/ask` endpoint itself does exist and stays
      (`CaddyAskController`, tested): it answers 200 for a host some brand or
      the operator console actually answers on and 404 for everything else,
      which is what stops a server from minting certificates for any domain
      pointed at it. A proxy that wants on-demand TLS can use it; writing
      that proxy's config is not this repository's job.

      What the application owes instead is already done: it trusts the
      proxy's forwarded headers (`config/trustedproxy.php`), including
      `X-Forwarded-Host`, without which brand resolution reads the proxy's
      internal hostname and every request gets the wrong brand or a 404. The
      trusted list is not a wildcard by default, precisely because a
      spoofable host would let a direct caller choose which brand it is
      talking to.

      Still owed by the deployment, not by this repo: the proxy must forward
      `Host` unmodified, and must be able to obtain a certificate for each
      brand domain.
- [x] Brand-scoped sessions (per-brand cookie names) + brand context
      serialized into queued jobs and restored in workers
- [x] Pennant module gating at all four points (routes, nav, slots,
      listeners — `ShortCircuitsInactiveModule`)
- [x] Block-based static brand pages: `BlockType` contract + registry via the
      `registerBlockTypes()` provider hook, render pipeline at `/pages/{slug}`
- [x] `BelongsToBrand` scope + strict models + laravel-data → TS pipeline
      (`composer types:generate` → `resources/js/types/generated.d.ts`)
- [x] One registration flow with multiple RegistrationTypes (axes model),
      free settlement only: submit → (approve) → confirm emits
      `RegistrationConfirmed`; Admission issues idempotently from the payload
      alone; Commerce stays dormant (ADR-0026)

## Phase 2 — release scope

**Round 6 gate review:** the spec-out session's decision set is now closed
(`docs/inventory/decision-log.md`, "Round 6 — the Phase 2 gate review").
**Round 7 build-out interview (2026-08-12):** everything Round 6 left open
or deferred to the paid-flow build is now settled — amendment/transfer
(ADR-0036), refunds vs check-in (ADR-0037), waitlist semantics, badge
templates, announcement contract, retention numbers, social drivers, and a
batch of accepted defaults. No inventory decision blocking Phase 2 remains
open.
Five user overrides added release scope beyond the recommended minimums: the
event-day concept, multi-venue events, web push, Messaging-owned digests, and
Registration-owned waitlists. See each affected module's `SPEC.md`/`TODO.md`
for what that adds to its definition of done.

Kernel: Platform, Identity, Event, Compliance, Messaging — **started**:
- Operator admin console (ADR-0029): own host resolved into OperatorContext,
  hardened brand scope, `permissions:sync` + Operator Admin role (ADR-0013
  live), module-contributed `routes/admin/` + admin navigation; Brands and
  Users pages shipped. Demo data via `konventix:seed` (Laravel Prompts).
- Identity: **auth lifecycle complete** — registration, email verification,
  2FA (confirmed), session list/revocation, account states, auth Data
  objects; `brand_memberships` (ADR-0016) recorded on login/registration
- LanCore UX adapted (same stack, so ports not rewrites): admin shell
  (module-grouped collapsible sidebar, search, server-persisted favorites the
  user can drag into their own order — ADR-0042, beyond LanCore),
  shared DataTable pattern, brand landing pages as block compositions
  (hero/cards from Platform, event-hero/countdown contributed by Event —
  the ADR-0003 extension proof), PublicTopbar, brand theme editor with
  BFSG contrast enforcement (ADR-0008 "refuses, not warns" — which caught
  and fixed non-compliant default status colors), per-event admin dashboard
  composed via UI slots (Registration/Admission contribute their own cards
  and endpoints). Deferred from LanCore: seat-map viewer (needs Resources),
  audit pages, dark-mode theme overrides.
- Event: Event + Venue models (wall-clock times, ADR-0027), lifecycle
  Actions + contract events, public landing route
- Compliance: policies/versions, consent records, `GdprHandler` contract +
  registry, DataSubjectRequest workflow with idempotent re-dispatch
- Messaging: `MessageChannel` contract + mail driver with per-brand
  transports (ADR-0028), DB-backed template registry with brand overrides,
  outbound queue pipeline
- Realtime transport: shared Reverb, one app per brand from brand rows
  (ADR-0025), per-brand origins enforced

Core: Registration, Admission, Commerce, Content — **invoice-settlement
chain shipped**: ADR-0017 price declaration on RegistrationType,
gate-dependent `RegistrationSettlementRequested`, Commerce order/lines with
ADR-0030 VAT treatment (reverse charge, §19 UStG), gapless per-brand
invoice numbers, hybrid ZUGFeRD PDF/A-3 archival (ADR-0031), manual
payment booking in the operator console, and `OrderPaid` driving
`on_payment` confirmation. Remaining for the exhibitor definition-of-done
item: Messaging delivery of the invoice document.
Modules: Resources, Competition (absorbs LanBrackets per ADR-0019 and owns
the `Discipline` abstraction per ADR-0024), GameOps, Staffing

Definition of done: our own events (SXLan, Xpertise) run end-to-end on
Konventix, including invoice-settled exhibitor registrations, entrance
scanning, seat/resource booking, shift planning, and tournaments.

### Build order to the definition of done

Sixteen slices, dependency-ordered (settled after the Round 7 interview;
each slice lands with tests, TODO.md updates, and pint/phpstan green).
Slices 8, 9 and 12 are independent of 5–7 and of each other, so they may
interleave.

1. Enablers: module-local Inertia page resolution (vite globs over
   `app-modules/*/resources/js/pages`), Platform media library (ADR-0035),
   role-assignment screen — these unblock every module UI, badges, Content.
2. Messaging minimum: `InvoiceIssued` → mail with the archived ZUGFeRD PDF
   attached (first real `SendMessage` consumer), recipient read model —
   closes the exhibitor definition-of-done item.
3. Event completion: multi-venue (D-EVT-15), event-day entity (D-ADM-04),
   re-entry knob (D-ADM-03), `predecessor_event_id` (D-EVT-14), remaining
   lifecycle Actions, admin CRUD. Event-days gate Admission validity.
4. Registration completion: waitlists (D-REG-12), invitation/link tokens
   (D-COM-02), organizer list with bulk approval, amendment/transfer
   (ADR-0036), registrant read model + GDPR exporter.
5. Admission entrance chain: signed tokens + entrance API + delta feed
   (ADR-0033), check-in records **emitting `registration_id`** (the
   ADR-0037 prerequisite), event-day validity, claim links (ADR-0032),
   `BadgeTemplate` + PDF rendering (D-ADM-05).
6. Offline entrance client (ADR-0002 carve-out) — new work, not a port.
7. Commerce completion: Stripe Connect Standard + Mollie adapters,
   `SettlementAccount`, public checkout for `instant`, dunning, refunds +
   credit notes (blocked-by-check-in per ADR-0037; credit notes also serve
   ADR-0036 transfer), bookkeeping export.
8. Resources: types in unit/quantity mode (ADR-0034), half-open bookings
   with exclusion constraints (ADR-0020), claims materialized from grants,
   admin UI, seat-map viewer port.
9. Staffing: flat teams (D-STF-02), shifts, self-service sign-up
   (D-STF-01), `AssignmentAccepted` → crew Admission, shift-plan UI.
10. Competition: `Discipline` (ADR-0024) over a GameOps catalogue read
    model (the catalogue piece of slice 11 lands first), per-event teams
    (D-CMP-13/14), signups on confirmed Registration (D-CMP-05), bracket
    engine absorbed from LanBrackets (ADR-0019), results + corrections
    (D-CMP-11/12).
11. GameOps orchestration: pools with per-pool reuse (D-GOP-01), bounded
    provisioning retries → desk handoff (D-GOP-02), heartbeats +
    `SessionUnhealthy` (D-GOP-03), idempotent jobs, encrypted per-brand
    credentials — consumes Competition's `MatchScheduled`.
12. Content: `ContentEntry` + typed content types, publish Actions, public
    pages, CMS block types, comments (account-required) + moderation,
    announcement intent contract (D-CNT-08).
13. Messaging full: web push driver (D-MSG-02), digests + quiet hours
    (D-MSG-03, urgent bypasses), per-brand delivery log + suppression
    (ADR-0028), in-app inbox.
14. Compliance completion + GDPR sweep — **done**: policy console and the
    opt-in consent gate, export assembly with a real fan-out, the hybrid
    audit trail, retention rules incl. the D-CPL-01 numbers, the DSR deadline
    scheduler, and a `src/Gdpr/` handler in every module holding personal
    data. Left standing: Commerce has no handler (an order cannot resolve a
    subject without reading Registration — see Compliance's TODO), and the
    per-module anonymization log has no screen.
15. Identity social login (**Steam only** — D-IDN-01 as amended 2026-08-19;
    Google and Discord dropped) + Platform polish (dark-mode token overrides,
    operator settings page, `brands.operator_id` tightened to non-null).
    **Done.** Steam landed as slice 15a and the Platform items as 15b, which
    also fixed the dark palette's own `danger-contrast` pair (3.62:1) — the
    first time ADR-0008's guard had been pointed at the platform defaults
    rather than at a brand's overrides.

16. Hardening + dry run: `konventix:seed` for both event archetypes, BFSG
    pass, then SXLan and Xpertise rehearsed end-to-end on seeded data.
    **Done** (2026-08-20).

    The archetypes: `EventArchetype` gives every seeded brand one of two
    shapes — a LAN party or a trade fair — and the difference between them is
    configuration only: which gateable modules are on, what the venues and
    days are called, what the registration catalogue offers, and what is
    bookable on the floor. SXLan seeds Players and
    Sponsors against a seat grid with tournaments; Xpertise seeds Visitors and
    Exhibitors against booth rows and meeting rooms with Competition switched
    off, which is the first time the seed has demonstrated the ADR-0003 gate
    rather than just described it. A test asserts both shapes come out of the
    same run, and it is the test that fails if an archetype ever grows a code
    path of its own inside a module.

    The BFSG pass was not the contrast sweep it sounded like — ADR-0008 had already put a guard on the tokens in
    both schemes. What it found was keyboard access, concentrated in the
    shared `DataTable`: no way to sort a column, unnamed pager arrows, and
    three tables whose rows were reachable by mouse only. Fixed there, plus a
    skip link in both shells, `aria-current` on the sidebar, and
    `prefers-reduced-motion` in app.css. `.ai/rules/js.md` carries the rules
    forward. Still owed: an assistive-technology run over the public pages,
    which no amount of static reading substitutes for.

    The dry run is a test rather than a session of clicking, so it stays
    true. `tests/Feature/DryRunTest.php`
    seeds both archetypes and then requests every named GET route the router
    holds: the console once (it is the operator's, not a brand's), the public
    and account screens once per archetype. Route parameters are resolved from
    the seeded data, so the pages are rendered against what the real Actions
    produced rather than against fixtures written to suit them.

    Two details make it worth more than a smoke test. The route table is the
    input, not a list somebody maintains, so a screen added without a thought
    for seeded data fails the day it lands; and the console is pinned to 200
    exactly, because the operator admin holds every capability and a redirect
    there is an authorization refusal wearing a success code — which is what a
    renamed permission looks like from the outside. Everything skipped is
    skipped by name with the reason beside it: the Steam round trip, the
    device-token entrance API, the proxy's certificate ask, the emailed
    one-time links, and the packages' own dashboards.

    It found no broken screen on its first honest run. What it did find was
    that two exports need a period before they will answer and the checkout
    page 404s for a registration that never produced an order — both correct
    behaviour, both now written down in the test rather than rediscovered.

    CI with PostgreSQL is **done** (2026-08-20) — and it was not a
    formality. The pipeline had been failing on every commit for a long
    time: `composer setup` migrates, and no database service was defined.
    It now runs Postgres and Redis services and the whole `ci:check` chain
    (eslint, prettier, vue-tsc, pint, PHPStan, the Pest suite) against a
    built frontend. The production Caddyfile left this slice with the TLS
    decision above.
17. Object storage backends (ADR-0043): media ULIDs in page blocks (the
    `hero` block stores a resolved URL today and would rot on any disk
    change — prerequisite for everything below), S3 driver on the `media`
    and `invoices` disks with direct public delivery where the deployment
    declares it, proxied private downloads, queued variant generation,
    brand-scoped disk resolution (ADR-0028 shape, per-brand buckets later),
    `storage:migrate` with verification, a storage doctor command, and
    Garage behind a compose profile as the development backend.
    **Done** — landed as 17a–17f; see Platform's `TODO.md` for what each
    letter covered.

**Slice 11 (GameOps) is the only slice of the build order not started.**
`app-modules/game-ops/` is still the scaffold `make:module` produced: no
migrations, no models, no tests. It blocks Competition's `Discipline`
catalogue read model, which that module's `TODO.md` carries as blocked.
Slices 8 and 9 shipped their domains only — Resources and Staffing have
models, Actions, events and tests but no controllers, routes or pages, which
is what the page catalogue below records as "P2 backlog".

**The gap the slices did not cover** is that a brand's public site had almost
nothing to navigate. Every slice built its own screens and each was correct
in isolation, while `/news`, `/events/{slug}` and the registration form were
reachable only by typed URL: the brand topbar's navigation held one entry,
"Home", and no module had ever contributed to `AREA_BRAND`. Closing it is
not a slice — it is each module registering what it owns:

- [x] Event contributes `/events` (a real public list, since "the current
      event" is not a boot-time constant) and Content contributes `/news`.
- [x] Registration contributes the landing page's call to action through a
      new `event.landing` UI slot, whose props it resolves server-side.
- [x] The member's own surfaces. `NavigationRegistry` grew a third area,
      `AREA_ACCOUNT`, rendered in the account menu; Registration contributes
      "my registrations" (list and detail), and Commerce contributes the pay
      link and the invoice PDF onto that detail page through a
      `registration.mine.show` slot.
- [ ] A standalone "my orders/invoices" list, which the slot above does
      *not* provide and cannot: an order is keyed by
      `settles_type`/`settles_id`, so Commerce can answer "what did this
      registration cost" but not "show me everything I owe". That needs the
      payer read model — the same prerequisite as Commerce's missing GDPR
      handler, and the same open decision, recorded under Known gaps below.

### Second build order — requested 2026-08-25

Fourteen features asked for after the brand site got its shell. Sorted here
into nine slices the way the first build order was: by what each one needs
to exist first, cheapest enabler earliest, and with every place where a
request reverses a decision already written down named as a reversal rather
than slipped in. Three prerequisites are not features anyone asked for, but
six of the fourteen items block on them, so they come first. The page
catalogue below is not re-audited for these; each slice lands its own rows
in the owning module's `TODO.md` when it lands.

**Prerequisites (owed before the slices that name them):**

- **P1 — `identity:replay-profiles` and a wider `UserProfileUpdated`.** The
  event carries `userId, email, displayName, locale, updatedAt` and nothing
  else, and no replay exists, so Content's `content_authors` and Messaging's
  recipients only know accounts created after their projection did. Every
  profile field added below (username, avatar, tagline) has to travel in
  this payload, and every read model that shows it has to be rebuildable
  from it — that is what ADR-0004 promises. One replay closes the existing
  Known gap and slices 22, 25 and 26 at once. (Identity's `TODO.md`.)
  **Done** (replay only — the payload widens with slice 25's fields).
- **P2 — the Commerce payer read model.** An order is keyed by
  `settles_type`/`settles_id`; Commerce cannot answer "what does this person
  owe" without reading Registration's tables. This is the open decision
  behind the unchecked "my orders/invoices" item above and behind the
  missing GDPR handler, and a shop (slice 24) makes it unavoidable: a cart
  belongs to a person before it belongs to any registration. The decision
  is which of the two shapes recorded under Known gaps — a subject read
  model in Commerce, or Registration's export carrying invoice references —
  and it should be an ADR, because it fixes the data flow for everything
  Commerce sells that is not a registration.
- **P3 — a filter contract for the shared table.** `HasDataTableQuery`
  knows `search`, `sort`, `dir` and `per_page`; the toolbar has a search
  box and a page-size select and no filter slot. Registration and Content
  each hand-roll their own `event`/`status`/`type` query parameters. Slice
  18 needs one declared shape — a controller names its filters and their
  allowed values, the toolbar renders them, the CSV export re-reads the
  same set — before a brand or event filter can be added to every list
  without writing it seven times.

18. **Console context: brand and event selectors, and the filters they
    prefill.** Platform, with each module's list controller. A brand
    selector and an event selector in the admin shell, held in the session
    for the operator (never in a brand cookie — the console has no brand,
    ADR-0029), and every list that spans brands or events reads them as the
    default value of its own brand/event filter — a filter the user can
    still clear, because "all brands" is a legitimate view of an operator
    console. The brand filter is a filter in the ADR-0015 sense, over a
    set the capability check has already authorized; it is not
    `where('brand_id', …)` as a scoping mechanism, which ADR-0010 forbids
    and which the `withoutBrandScope()` escape in every admin controller
    is precisely the alternative to. The event selector is harder than the
    brand one for a reason worth stating: Registration owns an
    `EventSummary` read model and can offer an event picker from it;
    Content, Commerce and Admission do not, so an event filter on their
    lists needs each of them to project the same summary from Event's
    contract events (ADR-0004; Content's `TODO.md` records this for its
    editor). The one existing brand picker — the roles page's native
    `<select>` — is the pattern to lift into the shell and then delete.
    Favorites already store a URL, so a favorite can pin a filtered view
    the day the filters are query parameters (ADR-0042). Same slice, since
    it is the same page: the admin dashboard gets an `admin.dashboard` UI
    slot like the per-event dashboard already has, and Platform
    contributes a diagnostics card linking Pulse, Horizon, the health
    dashboard and — outside production only — Telescope. Pulse and Horizon
    are in the sidebar already under `ViewDiagnostics`; `/admin/health` has
    a route and no link; Telescope has neither and stays behind
    `ViewRequestLog`, which is a separate capability on purpose because it
    is the power to read request bodies. Linking it makes the retention
    decision Compliance's `TODO.md` carries ("Telescope and Pulse hold
    personal data outside the retention machinery") more urgent, not less.
    Needs P3.

19. **Default role sets, shipped by the modules that own the
    capabilities.** Platform for the mechanism, each module for its sets.
    Today the only role any code creates is "Operator Admin", and
    `OperatorAdminSeeder` builds it by granting every permission row that
    exists — a brand gets no roles at all until someone opens the editor
    and assembles one capability at a time from twelve enums. ADR-0013
    says roles are data, and this does not change that: a module declares
    its *defaults* in code (a `defaultRoles()` hook beside its `Permission`
    enum — "Registration Desk", "Content Editor", "Entrance Staff",
    "Tournament Admin"), `permissions:sync` materializes them per brand as
    ordinary editable rows, and it never overwrites a role the operator has
    since touched: a default is a starting point, and a sync that reset a
    brand's edited roles would be the LanCore failure of config pretending
    to be data. Two consequences to write down: a module activated on a
    brand later (ADR-0003) gets its roles on activation, not only on
    deploy; and a role set is per brand scope only, since the operator
    scope already has its one role. Same slice: the four Permission enums
    whose docblocks still say they are "not wired to `permissions:sync`"
    and the two TODOs claiming the command does not exist get corrected,
    and Competition, Resources and Staffing get the capability enums their
    TODOs owe.

20. **Compliance: a public request page, and withdrawal as a policy
    setting.** Two items, one module, one slice.

    The public data-subject request page. `POST /data-requests` lives
    behind `auth` today and answers with a toast, not a mail; an export
    request gets no acknowledgement at all, only a deletion does. The
    public page is not simply the same form without `auth`: a subject
    without an account has no `subject_id`, and a request filed by anyone
    who can type an address is a way to have a stranger's data deleted. So
    the page captures an email, sends a verification link, and the request
    opens only when the link is followed — at which point the
    acknowledgement mail ("we received your request, the deadline is the
    24th") goes out instantly and the 30-day clock starts. That
    verification mail is the acknowledgement for a subject with no
    account. Both mails are Messaging templates with no topic, like the
    three Compliance already registers, because a legal notice is not a
    notification anyone may switch off (ADR-0041). Two things this
    reopens: the request is still brand-scoped while Round 6 settled it
    spans the operator (Known gaps) — a public page on one brand's host
    makes the two-archives problem visible to the public, so that decision
    lands here; and holding an address for a subject who may have no
    account is the copy-of-personal-data-for-deletion question Compliance's
    `TODO.md` already refuses to answer with code alone. It needs a
    retention rule for unverified requests (hours, then gone) written into
    the same ADR.

    Withdrawal as a per-policy setting. `WithdrawConsent` is
    unconditionally self-service: `DELETE /consents/{consent}` checks
    ownership and withdraws. The request is a boolean on `PolicyVersion`
    beside `requires_consent` — `withdrawal` in `{self_service,
    operator_approved}` — so that a policy the operator cannot let a
    person leave unilaterally (a photo release already acted on, a
    liability waiver for an event that has happened) turns the withdrawal
    into a request the operator accepts or refuses, with a reason, in the
    same console the subject requests use. Not a second queue: a
    withdrawal request is a `DataSubjectRequest` of a new type, so the
    deadline watcher, the audit trail and the refusal notice all apply
    unchanged. The privacy page already knows a consent is "required" and
    warns that withdrawing it re-raises the gate; it learns one more state,
    "withdrawal pending". The word "withdrawal" is already taken in the
    SPEC for something else — `PolicyWithdrawn` is a *policy* being
    retired — so the enum and the event are named for consent explicitly.
    The legal footing is worth one sentence in the ADR: GDPR Art. 7(3)
    makes withdrawal of consent as easy as giving it, so operator approval
    is only defensible for a policy whose basis is *not* consent — which
    means the setting is really "this policy's basis is contract or
    legitimate interest, and leaving it is a request, not a right", and
    the UI should say that rather than offer a switch that reads as
    optional.

21. **Footer content.** Platform. The link list, its editor and the
    `dialog` kind shipped on 2026-08-24; what is missing is content. Three
    additions, in order: the nullable `brand_id` the table was designed for
    (a brand's own social links beside the operator's imprint, which stays
    operator-wide because one legal entity answers for all its brands,
    ADR-0001); a content block per footer column — address, a short
    operator blurb, social icons — as a small block composition reusing
    the page-block registry rather than a rich-text field, so it is the
    same editing model as the landing page and inherits the media library
    (ADR-0035) and the token rule (ADR-0008); and the first dialog under a
    `dialog` key, cookie settings, which the `dialog` kind has reserved a
    slot for since it landed. The editor already owes ADR-0038's dirty
    guard; the reorder should become a drag like the favorites (ADR-0042)
    rather than stay a submit-in-list-order.

22. **Comments: names, links, reactions.** Content. The first half is a
    bug with a known fix: the moderation queue renders `author_user_id`
    raw because the controller never joins `content_authors`, though the
    read model exists and the same controller already does exactly the
    right keyed-`pluck` lookup for entry titles. Name and link land in a
    day; the link's target is Identity's admin user detail page, which the
    catalogue carries as P2 backlog and which becomes this slice's second
    deliverable. Accounts older than the projection still read
    "Participant" until P1 runs. Reactions are the larger half and need
    one decision first: the SPEC draws the line at "comments on an
    editorial entry are Content's, conversation between participants is
    not" (D-CNT-02), and a like is on the participant side of it — but a
    like on a news comment with no Community module installed is a Content
    feature or nothing. Ship it in Content as a per-`(user, comment)` row
    with a denormalized count on the comment — not the `report_count`
    shape, which is deliberately not deduplicated per reader and could not
    be trusted as a like count — behind a `CommentReacted` contract event,
    and make the reaction subject a `(module, entity_type, entity_id)`
    triple from day one rather than a foreign key to `content_comments`,
    because slice 26 will want to react to a gallery item and the comment
    table's hard FK to `content_entries` is already the reason gallery
    comments cannot reuse it. Needs P1 for the names to be complete.

23. **More than one live settlement account per brand.** Commerce. This
    reverses a shipped decision and must say so: `ConnectSettlementAccount`
    refuses a second live account, a partial unique index enforces it, and
    the exception's own docblock explains why — "which account settles a
    brand's money is always a single answer". What replaces the single
    answer is a *payment method* offered to the payer: a brand connects
    Stripe and Mollie and PayPal-via-Mollie, each still the brand's own
    account with the platform never in the flow of funds (ADR-0007), and
    the checkout page shows the methods the live accounts provide and lets
    the payer choose; the chosen account is recorded on the `Payment` row,
    which is already keyed per `(provider, external_id)` (ADR-0039), so
    webhook replays still converge. What stays single: one account per
    provider per brand, and one account per *order* once a checkout has
    started. Refunds go back through the account that took the payment,
    which the `Payment` row now names, so ADR-0037's check-in block is
    untouched. The bookkeeping export gains a provider column. The
    operator console's settlement-account page turns from "the account"
    into a list, and the Stripe `card_payments.status` check Commerce's
    `TODO.md` owes becomes the rule for whether a method is *offered*, not
    only whether it is connected.

24. **The shop: products, a cart, and a door for the rush.** Commerce
    with Registration for the entitlement-bearing half, gated per brand
    behind a new Pennant feature rather than a new module, since Commerce
    is Core and cannot be switched off. D-COM-10 already decided the
    split: an add-on that grants an entitlement is a RegistrationType with
    prerequisites, a pure good is a Commerce product — and deferred the
    product half past release. This slice is that half. `Product` and
    `ProductVariant` with the ADR-0030 VAT treatment, stock as a quota in
    the Registration sense (all-or-nothing under `lockForUpdate()`, which
    `ConsumesQuotas` already does), a `Cart` owned by a person (P2) that
    holds product lines and, where Registration contributes them,
    registration intents — so one checkout can settle a ticket and a
    T-shirt in one order, which is the whole reason a cart exists. The
    cart reserves stock for a bounded time and releases it on expiry, the
    same sweep the waitlist offers already run every fifteen minutes.
    Checkout stays hosted-only with webhooks as the only truth (ADR-0039)
    and picks a method per slice 23.

    The high-demand mode is a separate switch on the same feature, off by
    default, and it is a *waiting room*, not the sold-out waitlist
    Registration owns (that one queues for a place that does not exist
    yet; this one queues for a page that exists but must not be hit by a
    thousand people at once). When on, arrivals at the shop get a
    signed, position-numbered token in Redis and a holding page that polls
    its position; the shop admits a configured number per interval and a
    token past the head of the queue opens the cart with its stock
    reservation. Redis is the right home because it is the only shared
    state fast enough and because Octane workers must not hold it in
    process; the flag in `.env.example` that says "flip SESSION_DRIVER and
    CACHE_STORE to Redis once …" gets flipped by this slice, because a
    database-backed session under that load is the first thing to fall
    over. What this does not do: it is not a fairness guarantee against
    bots and does not pretend to be one — a signed token, a per-account
    cap and Horizon-visible admission rates are the honest scope; anything
    beyond that is a product in its own right. Load-tested before it is
    called done, against the seeded LAN archetype, with the number in the
    definition of done being the one the request names: a thousand
    concurrent arrivals with no 5xx and no double-sold stock. Needs P2 and
    slice 23; the standalone "my orders" list falls out of P2 and closes
    the last open item of the first build order.

25. **Profiles, and a page for them.** Identity for the account of
    record, Community for the page — which pulls Community forward from
    Phase 3, and the section below says so. Identity's `User` stays slim
    (ADR-0004: the LanCore god object is the named anti-pattern), so the
    additions are the profile-of-record and nothing more: `username`,
    unique operator-wide because the account is (ADR-0016), so it is the
    public handle on every brand; a tagline with a hard length; a status
    emoji; social links as a small validated map; `avatar_media_id` and
    `banner_media_id`. The Steam option is a copy, not a link: the linked
    identity already stores `avatar_url`, and "use my Steam avatar" fetches
    it into the media library once, because a profile picture that changes
    when Steam's CDN does is not the person's. Two things this surfaces
    that no other media consumer has hit: media rows are brand-owned and
    accounts are not, so an avatar is the first `media_items` row with no
    brand to belong to — either a nullable `brand_id` on media with an
    operator-owned disk, or the avatar lives per brand membership; that is
    a decision for the ADR, and the second shape is defensible (a person
    may want a different face at a LAN than at a trade fair). And the
    shared `auth.user` prop still serializes the whole model; it becomes
    the four-field shape Identity's SPEC promised — ULID, display name,
    avatar URL, locale — before an avatar reaches the topbar. The public
    profile page is Community's (`u/{username}`, LanCore's `u/Show`),
    visible to signed-in members of the brand only, showing the read model
    Community's SPEC already describes plus what the person chose to show,
    with comments through slice 22's generic reaction/comment subject. It
    is where "invite to a group" will go; groups and clans are Community's
    next slice after this one and are not designed here. Needs P1, which
    is what gives every existing account a profile on day one.

26. **Galleries.** Platform for the storage, Community for the feed. The
    media library stores raster images up to 10 MB and generates
    thumbnails; it stores no video and generates no poster frame, so the
    first deliverable is media-library work with no gallery in sight: a
    video mime allowlist, a size ceiling that means something on object
    storage, a queued poster-frame and transcode job beside
    `GenerateMediaVariants`, and usage rows written by the page blocks
    that reference media, which still record nothing (Platform's
    `TODO.md`) and which an orphan sweep needs before a gallery's uploads
    can be trusted to it. Then `Gallery` and `GalleryItem` in Community —
    brand-scoped, ordered, each item a media ULID (ADR-0035) — an operator
    editor over the existing picker, and the public surface: a tile feed
    at `/gallery` contributed to `AREA_BRAND`, a detail view with the
    reactions and comments of slice 22 and a share link that is the
    canonical URL and nothing more clever. Three visibilities: public,
    signed-in, and attended-this-event. The third reopens a decision on
    purpose: Content's `Audience` has exactly two values because
    participant-gating would couple Content to Registration state
    (D-CNT-07). It does not couple here: "attended" is Admission's fact,
    and Community learns it the way Commerce already does for ADR-0037 —
    an `AttendedEvent` read model projected from `AdmissionCheckedIn`,
    never a read of Admission's tables. ADR-0043 then does the rest of the
    work: an attendee-only image cannot be served by the object store's
    public branch, so non-public galleries stream through
    `MediaFileController` with the visibility check in front, and a gallery
    flipped from public to members-only has to invalidate what the direct
    branch already handed out — a URL that keeps working after the rule
    changed is the failure this design has to name. Needs slices 22 and
    25, and P1.

Left out on purpose, so it is not read as forgotten: the Steam avatar is
copied, not hot-linked; groups/clans are named as the slice after 25 and
not designed; the waiting room is not an anti-bot system; the multi-provider
slice keeps one account per provider. Each of 19, 20, 23, 25 and 26 reverses
or extends a written decision and gets its own ADR before code, which is
what "settled, do not re-litigate in code" costs when a decision is
revisited on purpose.

## Phase 3 — post-release

Community, Agenda, Sponsoring, Newsletter, Extensibility (external
webhook/OAuth API only after internal contracts are stable), BFSG audit pass,
optional multi-operator groundwork.

Community is no longer entirely here: slices 25 and 26 of the second build
order pull its profile read model, the profile page and galleries forward.
Chat, presence, achievements and groups stay post-release.

Also deferred here by decision, not by omission:

- **GoBD retention deletion** (ADR-0031, ADR-0043 point 9). Slice 17 ships
  reporting only — a scheduled job that names archived documents past their
  window, plus a manual command. What deletion *means* (§147 AO counts ten
  years from the end of the calendar year of issue, and a deletion that runs
  early is worse than one that never runs) needs its own decision before any
  operator is near the limit. Konventix has no invoice older than 2026, so
  the honest deadline is far out — but the fix is owed, not waived.

## Standing constraints

- BFSG accessibility: token contrast validation from the start (ADR-0008).
  Enforced in two places: `UpdateBrandTheme` refuses a brand's save (it does
  not warn), and eslint's `konventix/no-palette-colors` refuses a raw palette
  utility in any Vue file — host or module.
- No fund flows through the platform, ever (ADR-0007).
- Module status is tracked in each module's `TODO.md`, updated in the PR that
  changes the code — no generated dashboards.
- Everything is checked, everywhere. The frontend blind spot that let half the
  Vue files go unchecked is closed: `tsconfig.json`, eslint and prettier all
  cover `app-modules/*/resources/js/**` as well as `resources/js/**`, and CI
  runs the lot. A check that silently skips half the codebase is worse than no
  check, because it reports green.

## Known gaps carried forward

Findings that are nobody's slice yet. Each is a decision or a piece of work
that has an owner in a module's `TODO.md` where one exists; this list is only
so they are not rediscovered.

- **Commerce has no GDPR handler**, and that is a finding rather than an
  oversight: an order is keyed by `settles_type`/`settles_id`, so Commerce
  cannot resolve a subject to their invoices without reading Registration's
  tables (ADR-0004 forbids it). Either Commerce keeps a subject read model or
  Registration's export carries the invoice references. An issued invoice may
  not be deleted at all (§147 AO), so this is about export, not erasure.
  (Compliance's `TODO.md`.)
- **A subject request is still brand-scoped** while Round 6 settled that it
  spans the operator, so a person registered with two brands gets two
  archives. (Compliance's `TODO.md`.)
- **Telescope and Pulse hold personal data outside the retention machinery**,
  bounded only by their own prune schedules — a window, not a rule anybody can
  audit. (Compliance's `TODO.md`.)
- **Unlinking a social provider is not re-authenticated.** `password.confirm`
  is unsatisfiable for the passwordless accounts social login creates; the
  lockout guard is what protects the account today. (Identity's `TODO.md`.)
- **Messaging gaps unchanged**: recipient timezone, D-BATCH-7 inactive-module
  filtering, announcement audience narrowing, suppression/log operator
  screens, provider bounce webhooks, a real-browser push pass. (Messaging's
  `TODO.md`.)
- **`Data\OpeningPeriodData` carries no `#[TypeScript]` attribute**, so the
  venues form spells its shape out by hand and the two can drift. Add the
  attribute when the opening-times editor lands.

## UI page catalogue (LanCore port map, audited 2026-08-16)

LanCore and its satellites (LanBrackets, LanHelp, LanShout) shipped roughly
150 Inertia pages; Konventix has ~30 today. This catalogue maps every LanCore
surface to its Konventix home so no screen is silently forgotten. It is a
port *map*, not a port list — pages whose concept Konventix models differently
are marked absorbed, not copied. Detail-level gaps stay tracked in each
module's `TODO.md`; this section only fixes the page inventory and its phase.

Status: **✓** shipped · **S«n»** scheduled in that Phase 2 slice ·
**P2 backlog** = Phase 2, backend done, page unscheduled · **P3** = Phase 3 ·
**absorbed/dropped** = deliberately not ported 1:1.

### Kernel

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Platform | Admin shell, dashboard, Brands, Users, media library, role assignment, theme editor, block-based landing/static pages | admin shell, `themes/*`, `legal/*`, `Welcome`, `Countdown` | ✓ |
| Platform | Dark-mode token overrides in the theme editor | `themes/Edit` (dark palette) | ✓ |
| Platform | Operator settings page (legal seller identity, provisioning) | `settings/Organization` | ✓ |
| Identity | Auth flows, profile/security/appearance settings | `auth/*`, `settings/{Profile,Security,Appearance}` | ✓ |
| Identity | Linked accounts + social onboarding (Steam signup completion) | `settings/LinkedAccounts`, `onboarding/Username`, `auth/CompleteSteamProfile` | ✓ — LanCore's separate username-pick and Steam-profile screens are one `auth/CompleteSocialSignup` |
| Identity | Admin user detail page (memberships, sessions, states) | `users/Show` | P2 backlog |
| Event | Event index/edit/dashboard/lifecycle, venue admin, public event list + landing | `events/*`, `venues/*` | ✓ |
| Compliance | Policy admin CRUD; user-facing consent pages; privacy settings | `admin/policies/*`, `policies/{Required,Show}`, `settings/Privacy` | ✓ |
| Compliance | DSR console: export/deletion requests with their per-module fan-out; self-service request + download on the privacy page | `admin/data-lifecycle/*`, `account/{Delete,DeletionPending}` | ✓ |
| Compliance | Retention-policy admin | `admin/data-lifecycle/*` | ✓ |
| Compliance | Anonymization log (what a deletion actually changed, per module) | `admin/data-lifecycle/*` | S14 |
| Compliance | Audit pages (per-entity change history — LanCore had ten `*/Audit.vue`) | `users/Audit`, `events/Audit`, `news/Audit`, … | ✓ — absorbed into one filtered trail, not ported per entity |
| Messaging | Delivery log + suppression admin | `admin/emails/*` | S13 |
| Messaging | In-app inbox + archive; notification/digest/quiet-hours preferences | `notifications/*`, `settings/{Notifications,EmailSettings}` | S13 |

### Core

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Registration | RegistrationType admin CRUD (axes, price declaration, quotas, grants) | `ticket-types/*`, `ticket-categories/*`, `ticket-addons/*` | P2 backlog |
| Registration | Participant "my registrations" (list + detail) | `tickets/{Index,Show}` | ✓ — the amendment entry point is not there: ADR-0036 is organizer-side only so far |
| Registration | Amendment admin UI (ADR-0036), invitation-token management (D-COM-02), waitlist admin | — (new concepts) | P2 backlog |
| Registration | Purchase constraints | `purchase-requirements/*`, `global-purchase-conditions/*`, `payment-provider-conditions/*`, `settings/TicketDiscovery` | absorbed — type axes, quotas and invitation tokens replace LanCore's constraint system; no 1:1 port |
| Admission | Checkpoint/device enrolment, badge template designer (D-ADM-05), claim-link management (ADR-0032), entrance POS screen (ADR-0018) | — (LanEntrance satellite had no admin UI) | P2 backlog |
| Commerce | Orders admin index, settlement-account console, bookkeeping export, public checkout | `orders/Index`, `shop/Settings`, `cart/Checkout` | ✓ |
| Commerce | Order/invoice admin detail page; participant "my orders/invoices"; invoice-prefix settings | `orders/Show`, `my-orders/*` | P2 backlog — the per-registration half (pay link, invoice PDF) ships on Registration's account page; the standalone list needs the payer read model |
| Commerce | Voucher CRUD + redemption view | `vouchers/*` | P2 backlog (SPEC-owned, unscheduled) |
| Content | Entry editor + admin index, public listing/detail, comment moderation, announcements (+ public banner) | `news/*`, `announcements/*` | ✓ |
| Content | Knowledge-base content type | LanHelp `kb/*` | P3 — lands with Support (ADR-0022) |

### Pennant-gated modules

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Resources | Resource + resource-type admin, seat-plan editor, public seat picker | `seating/*` incl. `Picker` and the block/category editor | P2 backlog (slice 8 shipped the domain) |
| Staffing | Team admin + public team pages, lead roster board, crew "my shifts" | `orga-teams/*` | P2 backlog (slice 9 shipped the domain) |
| Competition | Competition admin CRUD + team management, tournament desk, public schedule/match/standings/team pages, stream bracket overlay | `competitions/*`, `events/CompetitionBoard`, LanBrackets `Competitions/*` + `Overlay/Bracket` | P2 backlog (slice 10 shipped the engine) |
| GameOps | Game catalogue CRUD (+ modes), operations surface (servers, APIs, jobs), participant "play next" portal | `games/*`, `orchestration/*`, `portal/PlayNext` | S11 |

### Phase 3

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Agenda | Program admin CRUD + public agenda | `programs/*` | P3 |
| Community | Chat rooms, achievements CRUD + user showcase, public profiles, presence | `chat/Room`, LanShout, `achievements/*`, `u/Show`, `settings/Achievements` | P3 |
| Sponsoring | Sponsor + sponsor-level CRUD, public sponsor display | `sponsors/*`, `sponsor-levels/*` | P3 |
| Newsletter | List management | `newsletter-lists/*` | P3 |
| Support | Ticket create/list/detail, staff queue, admin views (ADR-0022; the KB half lands in Content instead) | LanHelp `tickets/*`, `staff/*`, LanCore `admin-tickets/*` | P3 |
| Extensibility | Webhook CRUD + delivery detail, OAuth app management | `webhooks/*` | P3 (external contracts only after internal ones are stable) |
| — | Satellite connection screens | `integrations/*` (Create{LanBrackets,LanEntrance,LanHelp,LanShout}) | dropped — the satellites are gone (module-map) |
