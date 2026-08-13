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
- **Slice 3** (`fd17b51`): Event completion — multi-venue pivot with
  primary flag (D-EVT-15), `EventDay` entity with stable ULIDs across
  re-syncs + `EventDaysChanged` (D-ADM-04), `ReentryPolicy` (D-ADM-03),
  `predecessor_event_id` (D-EVT-14), Create/Announce/Start actions
  (Running is an explicit operator transition — settled), admin CRUD with
  per-state lifecycle buttons, Venues admin, Inertia landing page. The
  SPEC contract table carries 15 implemented events.

Verification state at handoff: **453 tests passing, PHPStan clean, pint
clean, `npm run build` green.**

## Next up (in build order)

Slices 4 and 5 are complete.

1. **Slice 6 — the offline entrance client** (ADR-0002's one sanctioned
   runtime-separation carve-out; new work, not a port). 5a was built to make
   this straightforward: `AdmissionTokenCodec` never touches the database, so
   the client reuses it verbatim, and `RecordScan` orders its checks to match
   what the client can decide locally — signature, expiry, event, day-scope,
   validity, re-entry — so both sides produce the same `CheckInRejectionReason`
   codes. The client consumes `GET /api/entrance/signing-keys`, polls
   `GET /api/entrance/delta?since=<seq>` every 30s, and posts buffered scans
   to `POST /api/entrance/scans` with its own `client_scan_id` per scan.
2. **Slice 7 — Commerce completion**, now unblocked: `AdmissionCheckedIn`
   carries `registration_id`, so ADR-0037's refund block can build its read
   model. Credit notes also serve ADR-0036's post-invoice transfer.
3. Slices 8–16 as listed in `docs/ROADMAP.md`.

Gaps deliberately left inside Slice 5, all in Admission's `TODO.md`: the
delta feed's `revoked` kind is defined and served but nothing writes it
(revocation has no Action yet, so only `checked_in` entries appear); no
operator screens for checkpoint/device enrolment, badge templates, or claim
links; and nothing emails a claim link — `urlFor()` builds it but an
organizer must copy it by hand.

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
