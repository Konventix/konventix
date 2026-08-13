# Session handoff — 2026-08-12

> Point-in-time note for continuing the Phase 2 build on another machine.
> Superseded by the next session's work; safe to update or delete once
> resumed. The durable plan lives in `docs/ROADMAP.md` ("Build order to
> the definition of done"); per-module state lives in each `TODO.md`.

## Where things stand

All Phase 2 decisions are settled — nothing blocks on interviews anymore.
Decision log Round 7 (`docs/inventory/decision-log.md`) closed the last
open questions; ADR-0036 (amendment/transfer) and ADR-0037 (refunds
blocked by check-in) captured the two big ones. The sixteen-slice build
order is documented in `docs/ROADMAP.md`.

Completed slices, one commit each, all pushed to
`github.com/Konventix/konventix-poc` (`main`):

- **Settlement chain** (`2740028`): the invoice-settled exhibitor flow —
  ADR-0017 price declaration, gate-dependent
  `RegistrationSettlementRequested`, ADR-0030 VAT engine (reverse charge,
  §19 UStG), gapless `PREFIX-YYYY-NNNNN` invoice numbers, ADR-0031 hybrid
  ZUGFeRD PDF/A-3 archive, manual payment booking in the operator console,
  `OrderPaid` → `on_payment` confirmation.
- **Slice 1** (`48c80f2`): module-local Inertia page resolution (host +
  `app-modules/*/resources/js/pages` merged by page name; component names
  resolve as `<module>/<path>` via `resources/js/lib/moduleComponents.ts`),
  ADR-0035 media library (`MediaLibrary::urlFor()` is the only sanctioned
  ULID→URL path), role-assignment screen (`admin/roles`, users by ULID).
- **Slice 2** (`90ee664`): Identity contract events
  (`UserRegistered`/`UserProfileUpdated`), Messaging recipient read model
  + attachments + explicit `brandId` on sends, Commerce
  `commerce.invoice-issued` template + `SendInvoiceMail` — the exhibitor
  definition-of-done flow is complete end to end including the mail with
  the sealed PDF attached.
- **Slice 4** (`8ed25bb`): Registration completion minus amendment —
  D-REG-12 waitlist (billing-snapshot entries, FIFO promotion with quota
  HOLDS at offer time, 48h signed claim links via Messaging, 15-min expiry
  schedule that re-promotes), D-COM-02 invitation/link tokens (use burned
  under lock), organizer list with bulk approval + streaming CSV,
  registrant read model, GDPR handler (tombstone anonymize), ADR-0036
  pre-invoice transfer freeze (`invoiced_at` from Commerce's InvoiceIssued,
  whose payload gained settles_type/settles_id). Architecture test now
  sanctions Compliance's Gdpr/Enums contract surface.
- **Slice 5 — Admission entrance chain**, split into three commits because
  the roadmap's single "slice 5" bundled nine areas:
  - **5a** (`eaead8b`): ADR-0033's offline trust model. KVX1 Ed25519 tokens
    (`KVX1.<kid>.<body>.<sig>`, body `{tid, nonce, iat, exp, evt, days}`) with
    keys that retire without expiring, so rotation never invalidates a QR
    already issued. Per-device bearer credentials (`Checkpoint` +
    `EntranceDevice`) replacing LanCore's one shared token. `routes/api/` —
    a new stateless third route group — serving signing keys, the PII-free
    delta feed (opaque IDs, monotonic-sequence cursor) and batch scan
    submission idempotent per `client_scan_id`. Check-in ledger emitting
    **`AdmissionCheckedIn` with `registration_id`**, the ADR-0037
    prerequisite that unblocks Slice 7's refunds. Day-scoped validity and the
    re-entry knob both decide from `EntranceEventProfile`, Admission's read
    model of Event. Cadence settled where ADR-0033 required it before build:
    30s poll, 15min stale, per-event overridable.
    *Found and fixed in Event:* `reentry_policy` was stored but published in
    no payload, so Admission had no lawful way to learn it — hence the new
    `EventReentryPolicyChanged`, emitted at creation as well as on change.
  - **5b** (`6fcf874`): `BadgeTemplate` + dompdf rendering (D-ADM-05).
    Everything inlines as `data:` URIs because dompdf ships with remote
    fetching off — which needed `MediaLibrary::dataUriFor()`, widening
    ADR-0035's sanctioned doorway rather than reaching around it.
    `bacon/bacon-qr-code` promoted to a declared dependency (lock unchanged).
    `BadgeIssuance` makes `reprint` a fact; the PDF is deliberately not
    stored, unlike a GoBD-archived invoice.
  - **5c** (`ba805cb`): claim links (ADR-0032). Temporary signed route whose
    signature expiry *is* the row's, mirroring the waitlist offer; the claim
    burns the link **under a row lock**, closing the two-people-race the ADR
    named as unbuilt. `AdmissionState::Assigned` finally has a producer.
- **Slice 4 completion — amendment** (`cd08512`): `AmendRegistration` adds
  grants to a confirmed registration as a delta (ADR-0036), never touching
  the original order or its immutable invoice. **Money is conditional**:
  the action always emits `RegistrationAmended` so the added admissions
  materialize, but requests settlement only when the delta carries explicit
  lines — crew badges come with an exhibitor's package, so the common
  amendment produces no order and no invoice at all. Lines are passed in
  rather than derived, because `GrantData` carries no price and a type's
  `price_declaration` prices the *type* (ADR-0017). Unblocking it meant
  re-keying idempotency in two other modules: Commerce moved from
  `settles_id` to `request_key` (the ULID of whatever requested settlement;
  unique on `settles_type, settles_id, request_key`, backfilled), Admission
  from "any admissions?" to per-`amendment_id`. Amendments consume quota
  all-or-nothing, one unit per added admission, and `ReleasesQuotas` now
  gives those units back — it released exactly one before, which would have
  stranded amended seats as sold forever.
- **Slice 6** (`7954517`): the offline entrance client, as an installable PWA
  at `/entrance` from its own Vite entry. No document named an artifact — a
  PWA was chosen because it keeps module-map's "not a second application"
  promise while adding no deployment target. Ed25519 verification is
  reimplemented in TypeScript (PHP's sodium cannot run in a browser); the
  check order mirrors `RecordScan` exactly so door and server return identical
  reason codes, pinned by tests on both sides. IndexedDB holds keys, delta and
  the scan buffer, all PII-free. New deps: `vite-plugin-pwa`, `@noble/curves`,
  and `vitest` — the repo had no JS test runner at all.
- **Slice 7a** (`cfd94d2`): internal money flows, no new dependencies.
  ADR-0037's refund block via Commerce's own `CheckedInAdmission` read model;
  credit notes on their own gapless `GS-` series; `SettlementAccount` plus the
  `SettlementAdapterRegistry` seam Stripe/Mollie plug into; the daily
  `commerce:dunning` sweep on the new `registerSchedule()` module hook.
- **Slice 3** (`fd17b51`): Event completion — multi-venue pivot with
  primary flag (D-EVT-15), `EventDay` entity with stable ULIDs across
  re-syncs + `EventDaysChanged` (D-ADM-04), `ReentryPolicy` (D-ADM-03),
  `predecessor_event_id` (D-EVT-14), Create/Announce/Start actions
  (Running is an explicit operator transition — settled), admin CRUD with
  per-state lifecycle buttons, Venues admin, Inertia landing page. The
  SPEC contract table carries 15 implemented events.

Verification state at handoff: **553 PHP tests + 17 TypeScript tests passing,
PHPStan clean, pint clean, vue-tsc clean, `npm run build` green.**

- **Slices 8, 9 and 12** — the three the roadmap marks independent of 5–7 and
  of each other, all domain-first with UI deferred:
  - **8 Resources** (`f78112b`): ADR-0020's half-open interval invariant as a
    **PostgreSQL exclusion constraint** — the repo's first `CREATE EXTENSION`
    (`btree_gist`), so migrations are now PostgreSQL-specific, which that ADR
    names and accepts. Unit mode is refused by the database; quantity mode
    (ADR-0034) adds a locked summed guard, and the constraint had to become
    partial over `is_exclusive` or quantity mode was impossible. `resource_claim`
    grants are finally materialized — they were silently dropped before.
  - **9 Staffing** (`4aa4c56`): flat teams (D-STF-02), self-service sign-up
    accepted outright with approval as the opt-in (D-STF-01), and the crew
    badge an accepted assignment produces. Required making
    `admissions.registration_id` **nullable**: a crew member is rostered, not
    registered, and inventing a synthetic registration would have put a fake
    row in Registration's tables to satisfy Admission's schema.
  - **12 Content** (`84415b2`): entries with a typed discriminator,
    `Scheduled` as a real state, announcements carrying intent only
    (D-CNT-08), account-required comments (D-BATCH-7), and a block
    contributed to *Platform's* registry rather than a second one.

- **Slice 10a — Competition domain** (`d78bd34`): `Discipline` per ADR-0024
  (a video game is one *kind*; duration/team size/scoring hang off it
  uniformly), per-event teams with a solo entrant as a team of one, entry
  gated on a confirmed Registration alone (D-CMP-05), and the match result
  state machine. `MatchScheduled`/`MatchFinished` emitted exactly as ADR-0012
  specifies even though GameOps does not exist, with `MatchFinished` fired
  once per match so a correction cannot make GameOps tear one deployment down
  twice. An arch test keeps the dependency one-way.
  Shipped **without** the GameOps catalogue read model: its producer is on
  hold *and* its contract is unsettled — GameOps' SPEC lists no catalogue
  event even as proposed and contradicts D-GOP-04 on the fields. ADR-0024
  sanctions shipping the table and reference now.

- **Slice 10b — bracket engine** (`PENDING`): five formats behind one
  `FormatGenerator` contract, plus `Stage` and the `match_connections`
  progression graph. The absorbed engine took a stage and mutated the
  database; **here the generators are pure functions** — seed-ordered
  `list<string>` in, a `BracketPlan` value object out — which is what lets
  `BracketMathematicsTest` prove every correctness property with no database
  at all, and leaves `GenerateBracket` as the only writer.
  Each construction is a published one and Competition's `SPEC.md` names it
  under "Bracket mathematics": standard recursive seeding, the circle method
  as a 1-factorization of `K_n` (Kirkman 1847), serpentine partition, the
  `2(k-1)` losers structure, and Swiss as minimum-weight perfect matching.
  **Two deliberate deviations from LanBrackets, both in the SPEC**: its
  single-elimination seeding is wrong (seed 1 versus seed 5 in an
  eight-bracket — its tests only ever asserted that seeds 1 and 2 were
  separated, never the pairing), replaced by the standard construction; and
  its double elimination has no crossover at all, so drop-in order is now
  reversed on alternate rounds. Swiss was **not** corrected: greedy first-fit
  stays, with its forced-rematch failure mode pinned by a test rather than
  hidden, because a blossom matching changes tournament behaviour and is a
  decision, not a commit.
  Also fixed a 10a bug this exposed — `competition_matches` was unique on
  `(competition_id, round, position)`, which a winners *and* a losers round 1
  position 1 both violate. Now scoped to the bracket, with `COALESCE` so 10a's
  stage-less matches stay constrained rather than silently unconstrained.

## Next up (in build order)

Slices 4, 5, 6, 8, 9, 10 and 12 are complete; 7 is half done.

1. **Slice 7b — payment providers.** Stripe Connect Standard and Mollie
   adapters, public checkout for `instant` settlement, and the
   `SettlementAccount` connect flow. The seam is in place: an adapter is one
   `SettlementAdapterRegistry::register()` line. Needs `stripe/stripe-php` /
   `mollie/mollie-api-php`, real provider credentials, and webhook routes with
   signature verification. Also owes the PSP side of refunds —
   `SettlementAdapter` still has only `markOrderPaid()`.
2. **Slice 7c — bookkeeping export.** Deliberately not started: **no format is
   decided anywhere in the docs** — no DATEV/EXTF structure, no account
   mapping. Write the ADR first.
3. **Slice 11 — GameOps**: deliberately on hold until there is infrastructure
   to test provisioning against. It also owes the catalogue event contract
   that Competition's `Discipline` read model is blocked on.
4. Slices 13–16 as listed in `docs/ROADMAP.md`.

Gaps deliberately left, all recorded in the owning module's `TODO.md`:

- **Admission**: the delta feed's `revoked` kind is defined and served but
  nothing writes it (revocation has no Action yet, so only `checked_in`
  entries appear); no operator screens for device enrolment, badge templates
  or claim links; nothing emails a claim link; the entrance client takes a
  token by keyboard/paste rather than camera, and has no ADR-0018 POS screen.
- **Commerce**: no credit-note PDF (the record exists, the document does not);
  partial credit notes reuse the invoice's tax split rather than recomputing;
  `OrderCancelled` is still proposed; `BookManualPayment` still passes the
  `manual` pseudo-account instead of the resolved account id.
- **Resources**: no admin UI or seat-plan surface; no scheduled hold-expiry
  sweep, so a lapsed hold keeps its slot until someone tries to confirm it;
  claim allocation is first-fit; `EventRescheduled` re-projects the window but
  does not yet *shift* existing whole-event bookings, which ADR-0020 assigns
  to this module.
- **Staffing**: no roster or my-shifts UI; no `StafferProfile` read model of
  Identity yet, so the roster shows ULIDs.
- **Content**: no editor, no public listing/detail routes, and the
  `PublishScheduledEntries` sweep has no scheduled command yet — it exists and
  is tested but nothing calls it on a timer.
- **Competition**: brackets generate and advance, but **standings are not
  persisted** — the ranking a round robin, group stage or Swiss is decided by
  has no model yet, and Swiss cannot pair round 2 without one.
  `Entrant::$checked_in_at` exists but nothing writes it, which is where
  D-CMP-05's admission check will be recorded. No tournament-desk or public
  surfaces. Swiss pairing is greedy first-fit, a documented limitation with
  the proven alternative (Edmonds' blossom) named in `SPEC.md`.
- **Repo-wide**: `npm run lint` reports 23 pre-existing ADR-0008 violations in
  other modules' Vue files. They predate this work and are untouched.

## Resuming on another machine

```bash
git clone git@github.com:Konventix/konventix-poc.git konventix && cd konventix
cp .env.example .env            # MUST precede `sail up` — see below
./vendor/bin/sail up -d         # needs an existing vendor/ — see below
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci && ./vendor/bin/sail npm run build
./vendor/bin/sail artisan test --compact
```

**The `.env` copy is load-bearing, not cosmetic.** compose.yaml feeds
`${DB_USERNAME}`/`${DB_DATABASE}` to the pgsql service, and Postgres honours
them only during first-time `initdb`. Start the stack without a `.env` and
they interpolate to empty strings — the cluster initializes as the default
`postgres` role and every later boot logs `Skipping initialization`, ignoring
the now-correct `.env` forever. Migrations then fail with
`SQLSTATE[08006] … role "konventix" does not exist`, which reads like a
connection fault but is a poisoned volume; the only fix is
`docker compose down && docker volume rm konventix-poc_sail-pgsql`. Note
`composer setup` cannot be the first step on Docker — it is what *creates*
`.env`, and it needs the containers already up. Full detail in
`.ai/rules/general.md`.

First-time bootstrap without `vendor/`: run composer through a throwaway
container (`docker run --rm -v $(pwd):/app -w /app laravelsail/php85-composer
composer install`) so `./vendor/bin/sail` exists. Ports: the host stack
assumes 80/5173/5432/6379/8090 free; `FORWARD_REVERB_PORT` dodges the
8080-range clashes (see `.ai/rules/general.md`).

Working conventions that matter mid-stream:

- Read `CLAUDE_extra.md` and `.ai/rules/index.md` first — two rule files
  were added recently (`data.md`: never name a static Data method `from*`
  unless it is a magic creator; `app-modules-src.md`: BrandScope hardens
  to zero rows on the admin host, escape it per relation in `withCount`).
- One slice per commit, push after each; end every slice with
  `pint --dirty`, `composer types:check`, the full `artisan test
  --compact`, `npm run build`, and TODO.md/SPEC.md updates in the same
  commit.
- `composer types:generate` after touching `#[TypeScript]` Data classes.
- The Boost `database-schema` MCP tool cannot reach pgsql from outside
  the containers — read migrations instead.
- `.mcp.json` now runs `php artisan boost:mcp` from the project root, so it
  works on any machine with `php` on `PATH`. It previously hard-coded
  `wsl.exe` and `/home/mkohn/...` paths pointing at a *different* repo
  (`konventix/konventix`), so Boost's MCP tools — including `record-rule` —
  silently failed to start anywhere but that one WSL setup. Machine-specific
  overrides belong in local settings, not this committed file.
- Tests run against a `testing` **Postgres** database, not SQLite:
  `phpunit.xml` overrides `DB_DATABASE` only, never `DB_CONNECTION`. Sail's
  `create-testing-database.sql` creates it during `initdb`, which is why
  that database's ownership matters after a volume rebuild.
- `QUEUE_CONNECTION=sync` in `phpunit.xml` runs jobs inline. A test that
  settles money for real must `Queue::fake()`, or `GenerateInvoiceDocument`
  executes and fails resolving its own brand context.

## Loose threads worth knowing about

- Registration's public form posts `days`-less; the invoice-settled path
  requires billing address fields (enforced in `SubmitRegistration::
  guardBillingParty()` for `settlement: invoice` only).
- Invitation tokens have no operator UI yet (issue/revoke via tinker) —
  Registration TODO backlog.
- Amendment is domain-only for the same reason: `AmendRegistration` has no
  operator screen, so amending means tinker. It also needs a capability
  (`registration.amend-registrations` is not in `Permission` yet). Both are
  in Registration's backlog — fold them into one admin pass with the token UI.
- Amendment pricing is caller-supplied by design: `AmendRegistration` takes
  explicit delta lines because grants carry no price. If per-grant pricing
  ever lands on `RegistrationType`, the priced path should derive the lines
  instead of trusting the caller — Registration TODO backlog.
- A waitlisted-then-promoted `on_payment` registration re-emits its
  settlement request at conversion time with then-current prices —
  Registration TODO backlog notes the pricing-drift question.
- Messaging recipients: accounts created before the projection existed
  have no read-model row — a backfill command is a Messaging TODO before
  relying on id-only sends for old accounts.
- Event admin: the Edit form routes schedule changes through
  `UpdateEventDetails` (emits `EventRescheduled` in any state);
  `RescheduleEvent`'s published-only guard is currently bypassed by the
  UI — noted in Event's TODO backlog.
- Role/media admin screens show bare user ULIDs pending the planned
  Platform identity read model (Platform TODO backlog).
