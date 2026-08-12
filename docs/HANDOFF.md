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
- **Slice 3** (`fd17b51`): Event completion — multi-venue pivot with
  primary flag (D-EVT-15), `EventDay` entity with stable ULIDs across
  re-syncs + `EventDaysChanged` (D-ADM-04), `ReentryPolicy` (D-ADM-03),
  `predecessor_event_id` (D-EVT-14), Create/Announce/Start actions
  (Running is an explicit operator transition — settled), admin CRUD with
  per-state lifecycle buttons, Venues admin, Inertia landing page. The
  SPEC contract table carries 15 implemented events.

Verification state at handoff: **365 tests passing, PHPStan clean,
`npm run build` green.** Working tree clean apart from this file.

## Next up (in build order)

1. **Slice 4 — Registration completion**: waitlists per D-REG-12
   (all-or-nothing across quotas, whole submission waitlists, FIFO
   promotion on `QuotaReleased` with 48h offers, states
   queued → offered → converted/expired/withdrawn), invitation + link
   tokens (D-COM-02), organizer registration list with bulk approval,
   amendment as delta orders + transfer freeze at invoicing (ADR-0036),
   registrant read model + GDPR exporter.
2. **Slice 5 — Admission entrance chain** (now unblocked — event days
   exist): ADR-0033 signed tokens + entrance API + PII-free delta feed,
   check-in records **emitting `registration_id`** (ADR-0037 needs this
   before refunds can build), day-scoped validity, ADR-0032 claim links,
   `BadgeTemplate` + PDF rendering (D-ADM-05, assets via media library).
3. Slices 6–16 as listed in `docs/ROADMAP.md`.

## Resuming on another machine

```bash
git clone git@github.com:Konventix/konventix-poc.git konventix && cd konventix
cp .env.example .env            # then set APP_KEY via sail artisan key:generate
./vendor/bin/sail up -d         # needs an existing vendor/ — see below
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm ci && ./vendor/bin/sail npm run build
./vendor/bin/sail artisan test --compact
```

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

## Loose threads worth knowing about

- Registration's public form posts `days`-less; the invoice-settled path
  requires billing address fields (enforced in `SubmitRegistration::
  guardBillingParty()` for `settlement: invoice` only).
- Messaging recipients: accounts created before the projection existed
  have no read-model row — a backfill command is a Messaging TODO before
  relying on id-only sends for old accounts.
- Event admin: the Edit form routes schedule changes through
  `UpdateEventDetails` (emits `EventRescheduled` in any state);
  `RescheduleEvent`'s published-only guard is currently bypassed by the
  UI — noted in Event's TODO backlog.
- Role/media admin screens show bare user ULIDs pending the planned
  Platform identity read model (Platform TODO backlog).
