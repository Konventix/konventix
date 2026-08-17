# Session handoff — 2026-08-17

> Point-in-time note for continuing the Phase 2 build.
> Superseded by the next session's work; safe to update or delete once
> resumed. The durable plan lives in `docs/ROADMAP.md` ("Build order to
> the definition of done"); per-module state lives in each `TODO.md`.

## Where things stand

Slices 1–10, 12, 13 and all of 7 are complete; every slice is one (or a
few topical) commits, pushed to `github.com/Konventix/konventix-poc`
(`main`). The full history of slices 1–12 with their design notes lives in
this file's previous revision (`git log docs/HANDOFF.md`) — this revision
keeps only the newest session verbose.

## This session (2026-08-17)

**Slice 13 — Messaging delivery model — is complete**, built on ADR-0041
(committed with 13a; interview decisions of 2026-08-16 are recorded in the
ADR itself). Five commits plus one repo-wide bug fix:

- **13a** (`7eb97e5`): ADR-0041 points 1–4. Topics as the unit of
  preference and policy, declared at boot via the new
  `ModuleServiceProvider::registerMessageTopics()` hook; `SendMessage` as
  the fan-out point (one envelope + one `messaging_messages` log row per
  resolved channel; preference/suppression refusals logged `suppressed` +
  `MessageSuppressed`, never silent); per-brand `Suppression` with
  partial-unique live rows and add/lift Actions + events;
  `NotificationPreference` rows in Messaging (D-MSG-06) with the
  `settings/notifications` page rendered from the registry; log bodies
  only for `logBodies`-flagged templates (D-MSG-07 hybrid). Both SPEC
  boundary lines (Identity preference custody) updated. A topicless send
  behaves exactly as before.
- **13b** (`0983975`): digests + quiet hours (point 5). `HoldPolicy`
  writes digestable non-urgent sends as `held` (30-min window per brand ×
  recipient × channel, joined by later sends; quiet hours 22:00–08:00 in
  recipient tz → brand `messaging_brand_settings.timezone` → app tz push
  the end to 08:00). `messaging:flush-held` (every minute, row-locked)
  sends single holds as themselves — payload rehydrated from the log via
  `RehydratedPayload`, locale from the new `recipient_locale` column —
  and coalesces several into one `messaging.digest`; members marked sent
  with `digest_message_id`. Urgent and attachment-carrying sends bypass
  holding (attachments are not persisted on log rows — TODO.md).
- **13c** (`005d521`): in-app inbox (point 6). `InAppChannel`'s delivery
  *is* the `InboxNotification` row; `NotificationReceived`
  (`ShouldBroadcastNow`, `notification.received`) pushes on the
  recipient's private Reverb channel. The bell
  (`messaging/NotificationBell`) mounts in `AppSidebarHeader` via the
  module component registry, fetches `/notifications` JSON once per
  browser page load and listens over Echo; mark-read emits
  `NotificationRead` on first read only. `generated.d.ts` also caught up
  several stale slices of enum types in this commit.
- **13d** (`4ff0291`): web push (point 7, D-MSG-02). `PushChannel` →
  `PushSender` seam → `WebPushSender` (minishlink/web-push, new approved
  dep). Typed `PushMessageData` (title/body/url/tag); per-device
  `PushSubscription` rows keyed (recipient, endpoint), pruned on 404/410;
  no devices = no-op; all-endpoints-refused = retry. One VAPID pair per
  deployment (`services.webpush`, `messaging:vapid-keys`; rotation
  invalidates all subscriptions). Static `public/push-sw.js`; per-device
  enable/disable on the notification settings page. PushSender resolves
  lazily so unconfigured deployments only fail sends that could deliver.
- **Fix** (`3dbf161`): **modular event discovery disabled** —
  InterNACHI modular auto-discovered every `src/Listeners/` class while
  every module also registers explicitly (ADR-0003), so **every listener
  ran twice per event** since the first one landed. Idempotent
  projections and request-key convergence masked it; Messaging's send
  listeners surfaced it as duplicate mail. `should_discover_events =>
  false` + `.ai/rules/listeners.md`.
- **13e** (`35d2f2a`): intent-event listeners (point 8).
  `SendAnnouncementNotifications` (queued) fans `AnnouncementPublished`
  to every account on `content.announcements` (in-app/push/mail,
  digestable, urgent bypasses — D-CNT-08); `SendDunningNotice` mails the
  billing contact on non-suppressible `commerce.dunning`.
  `DunningLevelRaised` gained billing scalars additively so the listener
  never reads Commerce models. Messaging owns both topics and templates;
  both SPEC boundary lines carry the (narrow) exception.

**Second half of the session — admin/UI sweep** (user request: consistent
console, dead-link-free navigation, View/Edit+Index for the core admin
areas), seven commits `77f542b`…`d23bc18`:

- **Nav cleanup**: brand hosts have no dashboard — `/dashboard` redirects
  to the landing page (route name kept as Fortify's post-login target),
  the stub page and the unused AppHeader/AppHeaderLayout shell are gone,
  sidebar links Home, PublicTopbar's logged-in CTA goes to account
  settings. A shop link joins the brand nav later.
- **AdminPageHeader**: one header component (title, description, actions
  slot) adopted by every console page, including the ones that
  double-padded or hand-rolled their own.
- **Brands**: real edit page — identity fields, host sync (first host
  primary), theme shortcut, and **per-brand module activation** toggles
  over Pennant (ADR-0003), gated by `platform.manage-module-activation`;
  `ModuleGate::allModuleNames()` added for the read.
- **Mail settings** (`/admin/mail-settings`, Messaging-owned, ADR-0028):
  per-brand sender identity, quiet-hours timezone, optional own SMTP
  transport; stored password never travels to the browser.
- **Users**: account page with profile edit (emits `UserProfileUpdated`),
  lock/unlock (first producers of `UserAccountLocked`/`Unlocked`, now
  implemented in the SPEC), brand memberships and cross-brand roles.
- **Orders**: detail page — lines, invoice + billing party + PDF,
  payment attempts, credit notes, book-bank-transfer in place
  (`bookPayment` now redirects `back()`).
- **Roles**: create dialog + grant editor rendering from the new
  `PermissionCatalog` (walks every module's Permission enum;
  `permissions:sync` delegates to it); undeclared grants refused.
- **Bell**: dropdown now links to `/settings/notifications` — the
  preference page existed but nothing led there.
- Venues kept their dialog-based create/edit (already complete); Events
  already had full CRUD and only needed the header alignment.

Verification at handoff: **767 PHP tests + 22 TS tests passing (1 PHP
skip), PHPStan clean, pint clean, vue-tsc clean, `npm run build` green.**

The user worked in parallel this session (admin favorites in Platform,
`SeedDemoDataCommand`, ROADMAP edits — all theirs, uncommitted at the
time of writing; none of it is in the commits above). Parallel test runs
share the one `testing` database and can transiently fail each other —
re-run before believing a red suite.

## Next up (in build order)

1. **Slice 14 — Compliance completion + GDPR sweep**: consent UI, export
   assembly, hybrid audit trail (D-BATCH: wildcard subscriber over
   auditable-marked events **plus** explicit recorder contract), retention
   rules incl. D-CPL-01 numbers (delivery log 12 months — Messaging's
   `messaging_messages` + inbox are ready to be swept), DSR deadline
   scheduler, anonymize-with-tombstone, and a `src/Gdpr/` handler in every
   module. Compliance's `TODO.md` Backlog is the authoritative item list —
   most design decisions are already settled in the decision log.
   **Touches every module** — coordinate with any parallel work.
2. **Slice 11 — GameOps**: still deliberately on hold (no infrastructure
   to test provisioning against; owes Competition the catalogue contract).
3. Slices 15–16 per `docs/ROADMAP.md`.

Messaging gaps deliberately left, all in its `TODO.md`: recipient
timezone never populated (Identity events carry none); D-BATCH-7
inactive-module filtering at inbox read (needs module attribution on
topics); announcement audience narrowing (recipient read model knows no
brand membership); suppression-list/log operator screens; provider bounce
webhooks; a real-browser push pass.

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
the cluster initializes as the default `postgres` role and every later boot
logs `Skipping initialization`; migrations then fail with
`SQLSTATE[08006] … role "konventix" does not exist` — a poisoned volume,
fixed only by `docker compose down && docker volume rm
konventix-poc_sail-pgsql`. Full detail in `.ai/rules/general.md`.

First-time bootstrap without `vendor/`: run composer through a throwaway
container (`docker run --rm -v $(pwd):/app -w /app laravelsail/php85-composer
composer install`) so `./vendor/bin/sail` exists. Ports: the host stack
assumes 80/5173/5432/6379/8090 free; `FORWARD_REVERB_PORT` dodges the
8080-range clashes (see `.ai/rules/general.md`).

Working conventions that matter mid-stream:

- Read `CLAUDE_extra.md` and `.ai/rules/index.md` first — `listeners.md`
  is new this session (explicit registration only; discovery is off and
  must stay off).
- One slice per commit, push after each; end every slice with
  `pint --dirty`, `composer types:check`, the full `artisan test
  --compact`, `npm run build`, and TODO.md/SPEC.md updates in the same
  commit.
- `composer types:generate` after touching `#[TypeScript]` Data classes.
- The Boost `database-schema` MCP tool cannot reach pgsql from outside
  the containers — read migrations instead.
- Tests run against a `testing` **Postgres** database, not SQLite:
  `phpunit.xml` overrides `DB_DATABASE` only, never `DB_CONNECTION`.
- `QUEUE_CONNECTION=sync` in `phpunit.xml` runs jobs inline. A test that
  settles money for real must `Queue::fake()`, or
  `GenerateInvoiceDocument` executes and fails resolving brand context.
- `BROADCAST_CONNECTION=null` and `MAIL_MAILER=array` in `phpunit.xml` —
  broadcasting and mail are safe to exercise for real in tests.

## Loose threads worth knowing about

- Registration's public form posts `days`-less; billing address enforced
  for `settlement: invoice` only (`SubmitRegistration::guardBillingParty()`).
- Invitation tokens and amendment have no operator UI (tinker only);
  amendment also needs a `registration.amend-registrations` capability —
  Registration TODO backlog, fold into one admin pass.
- Amendment pricing is caller-supplied by design (grants carry no price).
- A waitlisted-then-promoted `on_payment` registration re-emits settlement
  at conversion with then-current prices — pricing-drift note in
  Registration TODO.
- Messaging recipients: accounts created before the projection existed
  have no read-model row — backfill command still a Messaging TODO.
- Event admin: the Edit form bypasses `RescheduleEvent`'s published-only
  guard via `UpdateEventDetails` — Event TODO backlog.
- Role/media admin screens show bare user ULIDs pending the planned
  Platform identity read model (Platform TODO backlog).
- `npm run lint` reports pre-existing ADR-0008 violations in other
  modules' Vue files — untouched, repo-wide TODO.
