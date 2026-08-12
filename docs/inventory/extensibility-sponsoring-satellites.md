# Extensibility / Sponsoring / Satellite apps — LanCore inventory

What LanCore built for its outward-facing surfaces, what happens to each piece in
Konventix, and what to do with the two satellite applications in scope.

Two things frame everything below.

**Extensibility is post-release and gated on internal stability.** The module map,
`docs/architecture/overview.md`, and the module's own SPEC all say the same thing:
no external contract before the internal event contracts settle. So most rows in
this inventory are not "port this next", they are "this is the shape of the thing
we will eventually build, and here is what LanCore taught us not to repeat".

**ADR-0011 makes this small module commercially load-bearing.** Proprietary
plugins must reach the AGPL core only over the out-of-process tier (or across
clearly separated module boundaries). That is disproportionate weight for ~1,200
lines of LanCore code, and it is why D-EXT-05 exists.

Sponsoring is the opposite: five requirements, two models, no public surface worth
the name. Thin in LanCore, thin here, ported late.

## Sources

- `LanCore/docs/mil-std-498/SRS.md` — families INT (14 reqs), WHK (7), API (2),
  EXT (5), SPO (5), plus ICLIB (10) and COMP-F-021.
- `LanCore/docs/mil-std-498/OCD.md` §5.2.6, §5.2.6a — the satellite operational
  concept and the shared-client protocol.
- `LanCore/docs/mil-std-498/SSS.md` CAP-ICLIB-001, ENV-DEP-010 — the six planned
  satellites (LanBrackets, LanEntrance, LanShout, LanHelp, LanChart, LanBase).
- Code: `app/Domain/Integration/` (31 files), `app/Domain/Webhook/` (15),
  `app/Domain/Api/` (2 clients), `app/Domain/Sponsoring/` (21),
  `app/Console/Commands/ExternalApi/` (6),
  `app/Domain/Orchestration/Services/ExternalApiTester.php`,
  `config/integrations.php`, `routes/api-integrations.php`,
  `routes/integrations.php`, `routes/announcements.php`, `routes/sponsors.php`.
- Satellites read directly: `/home/mkohn/git/lan-software/LanHelp`,
  `/home/mkohn/git/lan-software/LanShout`, plus
  `/home/mkohn/git/lan-software/platform/demo-system/docker-compose.yml` and
  `platform/dev/init-databases.sh` for how they actually deploy.
- Konventix: `CLAUDE_extra.md`, `docs/architecture/overview.md`,
  `docs/architecture/module-map.md`, `docs/lessons-from-lancore.md`,
  ADR-0002/0003/0004/0006/0007/0008/0011/0012/0013/0014, and the existing
  `SPEC.md` of Extensibility, Sponsoring, Platform, Content, Community,
  Messaging, Staffing, Admission, Newsletter.

## Feature inventory

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Integration app registration | INT-F-001 | `IntegrationApp` row: name, slug, description, callback URL, allowed scopes, is_active, nav hints, two hardcoded webhook toggles. Global — no event or brand dimension | Extensibility | `ExternalApp` | Generalize (post-release) |
| API token generation, rotation, revocation | INT-F-002 | `IntegrationToken` with `expires_at`, `revoked_at`, `last_used_at`; rotate = new row, revoke = timestamp | Extensibility | `AppCredential` | Port renamed (post-release) |
| Token hashing and display prefix | INT-F-003, INT-F-013 | SHA-256 of a `lci_`-prefixed plaintext; stores hash plus 8-char prefix; `#[Hidden(['token'])]` on the model | Extensibility | — | Port as-is |
| OAuth2-like SSO authorization-code flow | INT-F-004 | `GET /sso/authorize?app=&redirect_uri=` mints a 64-char code, redirects away; `POST /api/integration/sso/exchange` swaps it for a user payload. No PKCE, no `state`, no refresh token, no consent screen; redirect URI validated by `str_starts_with` prefix match | Extensibility (+ Identity for the consent step) | Authorization Code grant | Generalize (post-release) |
| Bearer-token authentication middleware | INT-F-005 | `AuthenticateIntegration` hashes the bearer, loads token + app, checks revoked/expired/active, stashes both on request attributes | Extensibility | `authenticate-app` | Port renamed |
| Navigation hints on an app | INT-F-006 | `nav_url` / `nav_icon` / `nav_label` on the app row; LanCore's sidebar renders satellite links from it | Platform (registry) + Extensibility (entry) | nav registry entry | Split across modules |
| `IntegrationAccessed` event + webhook | INT-F-007 | Fires on SSO authorize; `HandleIntegrationAccessedWebhooks` fans it back out as an outbound webhook | Extensibility | `AppAuthorized` (operational) | Generalize |
| Stateless integration API routes | INT-F-008 | `/api/integration/*` loaded outside the `web` group: no session, no CSRF. Three endpoints, unversioned | Extensibility | `/api/v1/*` | Generalize (post-release) |
| Integration policy + capability | INT-F-009 | `IntegrationAppPolicy`, one coarse `Permission::ManageIntegrations` | Extensibility | `ManageExternalApps`, `ManageWebhookSubscriptions`, `ReadDeliveryLog` | Port renamed |
| Artisan integration management | INT-F-010 | create / list / revoke-token commands | Extensibility | — | Port as-is |
| Declarative `config/integrations.php` | INT-F-011 | Env-driven manifest of 4 apps: host, callback path, scopes, nav hints, webhook toggles + paths, pre-shared token and two pre-shared webhook secrets. Helm populates the env | — | — | Drop (see D-EXT-06) |
| `integrations:sync` reconciler + release-tagged logging | INT-F-012, INT-F-014 | Upserts each configured app; **deletes and recreates** its tokens and webhook rows on every reconciliation; logs each mutation with `LANCORE_RELEASE_NAME`/`_REVISION` so operators can correlate token invalidation with a deploy | — | — | Drop (see D-EXT-06) |
| Scope catalog | INT-F-001, INT-F-004 | Exactly three scopes — `user:read`, `user:email`, `user:roles` — as a static array literal inside `IntegrationAppController` | Extensibility | scope registry mapped onto module `Permission` enums | Generalize (post-release) |
| Scope-filtered user payload | INT-F-005, ICLIB-F-002 | `ResolveIntegrationUser` builds id/username/name/email/roles/locale/avatar/profile URL/created_at, filtered by scope | Extensibility (transport) + Identity (payload) | — | Split across modules |
| Server-to-server user lookup | INT-F-008 | `GET user/me` and `POST user/resolve` — the latter resolves **any** user by id or e-mail for any app holding a valid token. No per-user grant, no audit record | Identity | — | Generalize (tighten; see D-EXT-08) |
| Per-satellite create wizards | INT-F-001 | Four hardcoded Inertia pages: `CreateLanBrackets`, `CreateLanShout`, `CreateLanHelp`, `CreateLanEntrance`, plus four named routes | — | — | Drop |
| `lan-software/lancore-client` SDK | ICLIB-F-001..010 | Separate Composer package: `LanCoreClient`, `LanCoreUser` DTO, exception hierarchy, `VerifyLanCoreWebhook` middleware, 8 abstract webhook controllers, an opt-in entrance sub-client with JWKS caching, `::fake()` for satellite tests, and a public-display-field policy (ICLIB-F-010) | — | — | Drop (no satellites left to serve) |
| Webhook registration | WHK-F-001 | `webhooks` table: name, url, **one** `event` string, nullable secret, is_active. The requirement says "events" plural; the schema is singular | Extensibility | `WebhookSubscription` (endpoint + set of event types) | Generalize |
| Webhook event catalog | WHK-F-002 | 8 hand-maintained enum cases: `user.registered`, `announcement.published`, `news_article.published`, `event.published`, `ticket.purchased`, `user.profile_updated`, `user.roles_updated`, `integration.accessed` | Extensibility | event-type catalog derived from module public contracts | Generalize (post-release) |
| HMAC payload signing | WHK-F-003 | `X-Webhook-Signature: sha256=<hex>` over the JSON body, per-webhook secret. No timestamp, no replay window, no key id, no rotation window | Extensibility | — | Generalize |
| Delivery attempt log | WHK-F-004 | `webhook_deliveries`: status_code, duration_ms, succeeded, fired_at. **No response body** (the requirement claims one), no payload, no attempt number, no error text | Extensibility | `DeliveryAttempt` | Generalize (see D-EXT-04) |
| Asynchronous dispatch | WHK-F-005 | `SendWebhookPayload implements ShouldQueue`; `Http::timeout(10)->post(...)`, then `$response->throw()` inside a `try/finally`. No `$tries`, no `$backoff`, no dedicated queue, no dead-letter handling — retries are whatever the default queue worker does | Extensibility | — | Generalize (see D-EXT-04) |
| `sent_count` per webhook | WHK-F-006 | Incremented after the HTTP call but *before* `->throw()`, so it counts attempts including 5xx — it is not a sent counter | — | — | Drop |
| Webhook policy + capability | WHK-F-007 | `WebhookPolicy`, one `Permission::ManageWebhooks`; admin CRUD UI (Index/Create/Edit/Show) | Extensibility | — | Port renamed |
| Per-domain webhook fan-out listeners | WHK-F-005 | Six domains (News, Announcement, Event, Shop, Notification ×2, Integration) each import `Webhook\Actions\DispatchWebhooks` and hand-build a payload array. `HandleUserRolesChangedWebhooks` sends `'username' => $user->name` — the real name under a public field label | Extensibility | one subscriber projecting module events outward | Generalize |
| Inbound LanBrackets webhook | COMP-F-021 | `POST /webhooks/lanbrackets`, HMAC verified inline in `LanBracketsWebhookController` against `config('lanbrackets.webhook_secret')`, handled by `HandleLanBracketsWebhook` — inside the **Competition** domain, not Webhook | Competition | — | Port as-is (see D-EXT-03) |
| Public announcement feed | — | `GET /api/announcements/feed`, unauthenticated, unversioned, `throttle:60,1`, 30 s cache; hand-mapped JSON with fields (`severity`, `starts_at`, `ends_at`, `dismissible`) that do not exist on the model. Grown outside the Integration domain entirely | Content | — | Generalize |
| `AnnouncementAudience` enum | — | `internal` / `lancore_only` / `satellites` / `all` — deployment topology baked into a domain enum, and the feed filters on it | Content | visibility / channel | Generalize |
| TMT2 HTTP client | API-F-001, API-F-002 | Bearer auth, retries, `tmt2.enabled` flag, match create/get/update/delete against an OpenAPI-documented service | GameOps | `MatchHandler` driver (ADR-0012) | Port as-is |
| LanBrackets HTTP client | — (COMP) | `LanBracketsClient` for `/api/v1/competitions` CRUD — filed under the Api domain although only Competition uses it | Competition | bracket provider driver | Port renamed |
| `external-apis:test:tmt2` | EXT-F-001 | Connectivity probe, exit 0/1/2 | GameOps | provider health check | Generalize |
| `external-apis:test:stripe` / `:paypal` | EXT-F-002, EXT-F-003 | Validate PSP credentials with the same exit-code contract | Commerce | settlement adapter health check | Generalize (Stripe) / Drop (PayPal — not in the ADR-0006 adapter set) |
| `external-apis:test:steam` | EXT-F-004 | Resolves the sentinel vanity `gabelogannewell` against `STEAM_API_KEY` | Identity | `SocialProvider` health check | Generalize |
| `external-apis:test:listmonk` | EXT-F-005 | `ListmonkClient::health()` | Newsletter | driver health check | Generalize |
| `ExternalApiTester` + admin page | CAP-ORC-011 | One service in the **Orchestration** domain that imports Stripe, PayPal, Steam, Listmonk and TMT2 and returns a uniform `{status, account?, error?}`; backs both the console commands and an admin UI | Platform (contract) + each owning module (implementation) | `HealthCheck` contract | Generalize (see D-EXT-03) |
| Sponsor record | SPO-F-001 | `sponsors`: name, description, link, logo (a bare path string) | Sponsoring | `Sponsor` | Port as-is |
| Sponsor level | SPO-F-002 | `sponsor_levels`: name, `color` (raw hex, default `#000000`), `sort_order`; cached dropdown | Sponsoring | `SponsorshipTier` | Port renamed (colour must become a semantic token, ADR-0008) |
| Sponsor ↔ event assignment | SPO-F-003 | `event_sponsor` pivot, auto-increment id, timestamps only. No period, no weight, no terms | Sponsoring | `Sponsorship` (an entity, not a pivot) | Generalize |
| Sponsor ↔ programme / time-slot assignment | SPO-F-003 | `program_sponsor` and `sponsor_time_slot` pivots; rendered inline on the landing page | Sponsoring (+ Agenda by ULID) | `Placement` | Generalize (post-release) |
| Sponsor ↔ user assignment | SPO-F-003, SPO-F-004 | `sponsor_user` pivot plus a `sponsor_manager` role and a `ManageAssignedSponsors` capability — sponsor self-service in embryo | Sponsoring | sponsor contact / self-service grant | Defer post-release |
| Sponsor policies | SPO-F-004 | `SponsorPolicy`, `SponsorLevelPolicy`, three capabilities | Sponsoring | — | Port renamed |
| Sponsor audit trail | SPO-F-005 | `owen-it/auditing` on both models, plus dedicated `SponsorAuditController` / `SponsorLevelAuditController` and two `Audit.vue` pages | Compliance (mechanism) | — | Generalize |
| Sponsoring GDPR exporter | — | `SponsoringDataSource` exports sponsor-representative links via a raw `DB::table('sponsor_user')` query with a `Schema::hasTable` guard | Sponsoring | — | Port renamed |
| Public sponsor rendering | — | Hardcoded logo grid on `Welcome.vue`, plus sponsor lists inlined into the programme and time-slot sections of the same page. No placement model, no reusable component | Sponsoring (block types) + Platform (CMS) | sponsor block types | Generalize |

43 rows.

## Satellite apps

### LanHelp

**What it does.** A helpdesk and knowledge base. Four models: `Ticket`
(requester, assignee, subject, description, status, priority, category,
`context_snapshot` JSON, resolved/closed timestamps), `TicketReply` (with an
`is_internal` staff-note flag), `KnowledgeBaseArticle` (title, slug, content,
category, publish state, author), and a local `User`. Three roles — user, staff,
admin. A user files a ticket and replies to it and may only move it between open
and closed; staff work a board at `/staff/tickets`, assign tickets, and post
internal notes; admins get a stats dashboard and KB CRUD. The KB is public and
unauthenticated at `/kb`. Notifications are e-mail only, and staff alerting is a
single address in `LANHELP_STAFF_EMAIL`.

The one genuinely interesting piece is the contextual deep link.
`TicketController::sanitizeContextSnapshot()` whitelists `source_product`,
`source_domain`, `event_reference`, `seat_reference`, `tournament_reference`,
`order_reference`, `team_reference` and up to ten links. LanCore's
`AskForHelpButton.vue` builds exactly that URL. So "help me with *this* order"
already works — across an app boundary, by URL-encoding the context that would
otherwise be a foreign key.

**Size.** ~240 PHP files repo-wide, of which 62 live in `app/` — 3,025 LOC. Four
models, 13 controllers, 35 route definitions, 5 project-specific migrations.
179 Vue files, but 125 of those are vendored shadcn-ui components and 20 are
actual pages; of 18,192 LOC under `resources/js`, 8,988 is Wayfinder-generated
TypeScript. The bespoke product is roughly 3 kLOC of PHP and a few thousand lines
of Vue.

**Coupling to LanCore.** Total, and via one shared package. `composer.json`
depends on `lan-software/lancore-client ^0.2.0`; LanHelp writes no HTTP client of
its own. Three channels plus a UI contract:

- **SSO out** — `LanCoreAuthController` calls `ssoAuthorizeUrl()` then
  `exchangeCode($code)`, then `UserSyncService::resolveFromLanCore()` and
  `SyncUserRolesFromLanCore`. Users are shadow rows: `lancore_user_id` unique,
  `password` and `email` nullable "for LanCore shadow users", and
  `ProfileUpdateRequest` / `PasswordUpdateRequest` / `ProfileDeleteRequest` all
  return `! $this->user()->isLanCoreUser()` — the local identity features exist
  only to be switched off.
- **Roles webhook in** — `POST /api/webhooks/roles`, package middleware
  `lancore.webhook:user.roles_updated`, mapping `superadmin|admin → Admin`,
  `moderator → Staff`, with the comment "LanCore is authoritative for all shared
  roles".
- **Announcements pulled** — `LanCoreAnnouncementFeed` polls
  `/api/announcements/feed` with a 2 s timeout and 60 s cache, shared into every
  Inertia response.
- **Deep link in** from `AskForHelpButton.vue`.

It cannot run without LanCore in its normal configuration: both the landing page
and the login page redirect to `lancore.redirect` when `LANCORE_ENABLED` is true,
and the demo compose gives it `depends_on: lancore (service_healthy)`. Local
login survives only as `/login?local`.

Worth recording as evidence of the integration tax: LanCore is configured with
`send_announcements => true` and `announcement_path => /api/webhooks/announcements`
for LanHelp, but **LanHelp implements no such route** — it pulls the feed
instead. That push has been dead-lettering, or the flag is false in every real
environment and nobody noticed. Two systems, one config file, no way to tell.

**Does it need its own deployment?** No. It has the machinery — a three-stage
Dockerfile on `ghcr.io/lan-software/lanbase:php8.5`, `docker-publish.yml`,
`tests.yml`, `lint.yml`, its own compose file — but nothing forces it. Same
Laravel 13, same PHP, same Inertia v3, same Vue 3.5, same Tailwind v4, same
Postgres cluster (`DB_DATABASE=lanhelp` is a logical database on the shared
server), same Redis, same Mailpit, same `lanparty` network, no websockets
(`BROADCAST_CONNECTION=log`), no scheduler, no separate uptime tier, no offline
mode — indeed the opposite of an offline mode. The separation is organisational
(one repo, one image, one Weblate component per satellite), not technical.

**Recommendation: split it — absorb the knowledge base into Content, and decide
the ticket system separately (D-EXT-01). Do not keep the app.**

The KB is a `ContentEntry` with a content type. Content already owns publication
lifecycle, slugs, taxonomy, authorship-by-ULID and public listing routes; a
`knowledge_base` type plus a category is close to free, and it deletes
`KnowledgeBaseArticle`, its controller, its admin CRUD and its public routes
outright.

The helpdesk is the hard part, because it maps to **no existing Konventix
module**. It is not Community (that is participant-to-participant), not Messaging
(that is outbound delivery, and its SPEC explicitly refuses to become a place
where other modules' triggers accumulate), not Staffing (a requester is usually
an attendee, not a staffer), not Content. Whichever way D-EXT-01 goes, absorbing
it makes the deep-link contract disappear: `order_reference` and `seat_reference`
stop being URL-encoded strings and become the ULIDs they always were.

Note one thing before anything is copied: a live-looking
`LANCORE_TOKEN=lci_...` is committed in the repo's working-tree `.env`. Treat it
as burned.

### LanShout

**What it does.** A chat wall. Not casting, not a display system, not OBS — the
name misleads. Six models: `Message` (nullable `user_id` so LanCore can post as
nobody, `body`, `type` = chat or announcement, `priority` silent/normal/emergency,
soft deletes), a local `User` carrying moderation state (`is_blocked`,
`block_reason`, `timed_out_until`, `chat_color`, `last_chat_read_at`), home-grown
`Role`/`Permission` tables, `ChatSetting` (slow mode on/off and interval), and
`FilterChain` — moderation rules with `type` exact/contains/regex, an action of
block/replace/warn, and a priority order.

Workflows: an infinite-scroll chat page with an unread divider, live append over
a public Reverb channel with optimistic local echo; a moderation pipeline that
sanitises then runs the filter chain; slow mode returning 429 with
`remaining_seconds`; moderator timeout/block; an admin dashboard with time-series
charts; and a `chat:send` Artisan command that posts as `[System]`.

Announcements are **only** created by the LanCore webhook —
`AnnouncementController::store` writes `user_id => null, type => 'announcement'`.
There is no UI to author one. LanShout is a rendering surface for someone else's
content.

**Size.** ~228 PHP files repo-wide, 48 in `app/` — 2,751 LOC. Six models, 11
controllers, 52 registered routes, 18 migrations. 168 Vue files of which 119 are
shadcn-ui boilerplate and 16 are pages; 15,935 LOC under `resources/js`, much of
it Wayfinder output. The hand-written product is ~2.7 kLOC PHP and maybe 6–7 kLOC
of frontend.

**Coupling to LanCore.** Same shape as LanHelp but hand-rolled. `composer.json`
has **no** `lan-software/lancore-client` dependency, despite SSDD §168 stating
that all five satellites route through it — LanShout carries its own
`app/Services/LanCore/LanCoreClient.php`, `LanCoreUser`, `UserSyncService` and
`app/Http/Middleware/VerifyWebhookSignature.php`. The documented refactor was
never done, which is precisely the drift a separate repo makes cheap to ignore.

- **SSO out** — `/sso/authorize`, `/api/integration/sso/exchange`,
  `/api/integration/user/resolve`, and `/api/integration/user/me` (present but
  unused, commented as "for a future seamless browser SSO flow").
- **Two webhooks in** — `POST /api/announcements` and `POST /api/webhooks/roles`.
  Signature verification is HMAC-SHA256 over the raw body, and
  **silently skipped when the configured secret is empty** — a misconfiguration
  turns both endpoints into open write APIs.
- **Shadow users and duplicated RBAC** — own `roles`/`permissions`/`role_user`/
  `permission_role` tables, resynced on every `Login` event, with a `ROLE_MAP`
  translating `superadmin → super_admin` because the two vocabularies already
  diverged.
- Separate logical database (`lanshout`), same Postgres cluster, same `lanparty`
  network.

Three concrete contract drifts, all invisible from either side: LanCore defaults
`announcement_path` to `/api/webhooks/announcements` while LanShout listens on
`/api/announcements`; LanCore defaults `callback_path` to `/auth/callback` while
LanShout's is `/auth/lancore/callback`; and the shared client package is
documented but absent. Each needs an env override to work, and nothing fails
loudly when the override is missing.

**Does it need its own deployment?** The only real argument is **Laravel Reverb**
— a long-lived websocket process on its own port, in its own container, on the
shared network. That is a process-topology concern, not an application-boundary
concern: Konventix already runs one image in multiple roles (web, workers by
queue name, scheduler), and a Reverb role is the same kind of thing. Everything
else argues against separation: no kiosk or wallboard mode (the pages are Chat,
Dashboard, Landing, admin, auth, settings), no offline operation — with
`LANCORE_ENABLED=true` an anonymous visitor at `/` is bounced straight to LanCore
— no separate network zone, no separate uptime tier. It also has no Dockerfile in
the tree at all, although `ghcr.io/lan-software/lanshout:main` exists and the
demo stack pulls it; the image is built from something nobody can read here. And
the demo stack runs **no Reverb service and sets no broadcast connection**, so
live chat is almost certainly dead in the public demo today. A separately
deployed realtime app whose realtime does not run is not an argument for separate
deployment.

**Recommendation: absorb. Chat, filters, slow mode and moderation into
Community; announcements are already Content's. Drop the app.**

Community's SPEC already owns "Channels, membership, messages, and their
moderation state" plus "mutes, bans, and message removals". `FilterChain`,
`ChatSetting`, timeouts and blocks land there unchanged. `Message.type =
announcement` disappears entirely: Content publishes an entry, Community renders
a pinned strip from a read model built off Content's publish event, or the
Platform UI-slot registry renders it — no webhook, no signature, no path
mismatch, no `AnnouncementAudience::Satellites`. The duplicated RBAC dies against
ADR-0013. The absorbed code is ~2.7 kLOC minus everything that only existed to
talk to LanCore (six classes) and everything shadcn gave it for free.

Two things to fix on the way in, not after: `DashboardController` builds
statistics with `DB::raw("DATE_FORMAT(created_at, ...)")` — MySQL syntax against
a Postgres database, so `/dashboard/statistics` is broken today — and the same
committed-token problem as LanHelp (`LANCORE_TOKEN` and
`LANCORE_WEBHOOK_SECRET=testingsecretreallylongandsecure` are in the working-tree
`.env`).

The residual question is not "which module", it is "where does Reverb run" — and
that is a Platform deployment-role question that Community should not have to
answer alone.

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| `IntegrationApp` | `ExternalApp` | "Integration" names a relationship, not a thing, and it invited a global manifest of four named friends. An *app* is a client someone registers — per brand, at runtime, through the UI, by people we have never met. The rename is what kills `config/integrations.php`. |
| `IntegrationToken` | `AppCredential` | A token is one credential type. Naming the container generically leaves room for a client secret, a signing key, and a refresh token without a second table. |
| `Webhook` (one row = one event) | `WebhookSubscription` (endpoint + event-type set) | LanCore's requirement says "events" and its schema says one string, so subscribing to five events means five rows, five secrets, five `sent_count`s. Calling it a subscription makes the endpoint the entity and the event set an attribute, which is the only shape in which secret rotation and per-endpoint backoff are expressible. |
| `WebhookDelivery` | `DeliveryAttempt`, under a `Delivery` | LanCore's row *is* an attempt but is named as if delivery happened once. Naming the attempt forces the question "what is the delivery, then?" — and the answer is a durable record holding the payload, which is what makes retry, dead-lettering and replay possible at all (D-EXT-04). |
| `WebhookEvent` enum (8 hand-written cases) | event-type catalog derived from module public contracts | A hand-maintained parallel list drifts from the events modules actually emit — and LanCore proved it, by letting six domains hand-build payloads that duplicate their own event data. If the catalog is derived, an external contract cannot silently diverge from the internal one. |
| `allowed_scopes` (free string array) | scope catalog mapped onto `Permission` enums | Three string literals in a controller cannot express "may read registrations for this event". Mapping scopes onto the capability enums modules already own (ADR-0013) means the external surface can never grant more than the internal model recognises. |
| `AnnouncementAudience::LancoreOnly` / `::Satellites` | visibility / channel | The deployment topology is inside a domain enum, and the public feed filters on it. Under one application "satellites" is not a value that means anything — so the enum has to be rewritten in terms of *who may see this* and *where it appears*, which is what it should always have said. |
| `SponsorLevel` | `SponsorshipTier` | A "level" is a property of the sponsor; a tier is a property of the deal. The rename separates who the organisation is from what they bought this year, which is the prerequisite for a sponsor appearing at different tiers across two events. |
| `event_sponsor` pivot | `Sponsorship` entity | A pivot with only timestamps cannot carry the active period, the display weight, or the link to what was invoiced. Every question anyone actually asks about a sponsorship is an attribute this pivot has nowhere to put. |
| Sponsor `color` (raw hex on the tier) | semantic token reference | ADR-0008 forbids palette values in components; a `#000000` default in the database is the same violation one layer down. |
| `external-apis:test:*` (one central tester) | per-module `HealthCheck` implementations | One service in the Orchestration domain importing Stripe, PayPal, Steam, Listmonk and TMT2 is a cross-module god object with a friendly name. The rename puts each probe next to the driver it probes. |
| LanHelp `Ticket` | `SupportRequest` | "Ticket" already means an admission credential in this domain (ADR-0005 splits Registration from Admission precisely to clean that word up). Importing a second meaning would undo that. |

## Dropped / deferred, and why

**Dropped outright**

- **`config/integrations.php` and `integrations:sync` (INT-F-011..014).** A
  declarative manifest of four named applications, populated by Helm, reconciled
  at boot, that **deletes and recreates** every token and webhook row for a
  configured slug on each run. It exists because the four apps were ours and
  their credentials had to be seeded at deploy time. Konventix has no satellites
  to seed, and an external app that a third party registers cannot live in a file
  the operator deploys. See D-EXT-06.
- **The four per-satellite create wizards** (`CreateLanBrackets`,
  `CreateLanShout`, `CreateLanHelp`, `CreateLanEntrance`) and their routes.
  Product names hardcoded into an admin UI.
- **`lan-software/lancore-client` (ICLIB-F-001..010).** A whole Composer package —
  client, DTO, exception hierarchy, webhook middleware, eight abstract webhook
  controllers, an entrance sub-client with JWKS caching, a test fake, and a
  public-display-field policy — whose only job is to make six satellites talk to
  one core. Under ADR-0002 there is nothing left for it to connect. Its
  *existence* is the clearest possible measurement of the satellite tax: ten
  requirements and a versioned release train to avoid writing the same HTTP
  client six times.
- **`sent_count` (WHK-F-006).** Increments before `->throw()`, so it counts
  attempts, not sends. A `DeliveryAttempt` table answers the question properly.
- **`external-apis:test:paypal` (EXT-F-003).** ADR-0006 names Stripe, Mollie and
  bank transfer as the settlement adapters. No adapter, no probe.
- **`LANCORE_RELEASE_NAME` / `_REVISION` reconciliation logging (INT-F-014).**
  A workaround for the reconciler invalidating tokens on deploy. Remove the
  reconciler and the log line has nothing to explain.

**Deferred to post-release (the whole Extensibility module, by decision)**

Everything under INT and WHK is Phase 3. The gate is not the calendar, it is the
stability of the internal event contracts (`docs/ROADMAP.md`, module map,
overview non-goals). LanCore is the argument for the gate: its outbound catalog
is eight events long, four of the eight name concepts Konventix has already
redefined (`ticket.purchased` splits into Registration and Admission under
ADR-0005; `user.roles_updated` becomes per-brand data under ADR-0013), and a
ninth external surface — the announcement feed — grew outside the Integration
domain with no version and no auth at all. Publishing that catalog early would
have meant maintaining it forever.

**Deferred within Sponsoring**

- **Sponsor self-service** (the `sponsor_user` pivot, the `sponsor_manager` role,
  `ManageAssignedSponsors`). It pulls Identity, per-brand capabilities and an
  upload path into a module whose whole point is staying small.
- **Programme and time-slot placements.** They depend on Agenda, which is also
  post-release.

## Open decisions

### D-EXT-01: What replaces LanHelp's ticket system

**Context:** The knowledge base is a settled question — it becomes a Content type
and the code disappears. The helpdesk half does not map to any of the 18 modules.
It is ~1.5 kLOC of real logic (ticket, replies with internal notes, three-role
workflow, four e-mail notifications) with one genuinely valuable feature: the
context snapshot that lets "help with this order" arrive with the order attached.
Our own events do use it; whether a general event platform needs it is the open
part.

**Option A — a new `Support` module, post-release.** Owns SupportRequest, replies,
assignment, and the context reference (ULID + owning module, not a URL string).
Pros: keeps a feature we use; the context link becomes a real reference and the
"ask for help about this" affordance can be a Platform UI slot any module renders
into; roughly a one-week port of code that already works. Cons: a nineteenth
module needs approval and is not in the module map; it overlaps conceptually with
Messaging (inbound conversation vs. outbound delivery) and Community (threads and
moderation) and will attract "why isn't this just X" forever; and helpdesks grow
— SLAs, macros, escalation, satisfaction surveys — which is exactly the kind of
scope a 1–3 person team should not own.

**Option B — drop the helpdesk; route support to an off-the-shelf tool.** Keep the
KB in Content, and let the "ask for help" affordance deep-link into whatever the
operator already uses. Pros: deletes the most code; support tooling is a
commodity with good free tiers; it is the honest answer to "is a ticket system
part of an event platform". Cons: loses the context snapshot unless the external
tool accepts structured metadata (most accept a URL, which is what LanCore
already does — so this is nearly the status quo minus our maintenance); support
history stops being GDPR-exportable through Compliance, which is a real
obligation, not a nicety.

**Option C — SupportRequest as a Content type with a workflow.** Content already
owns entries, comments, moderation and publication state; a support request is an
unpublished entry with an assignee and a comment thread. Pros: no new module; the
KB and the tickets live in one place, which is where "this answer should become
an article" naturally happens. Cons: it is a genuine category error — Content's
SPEC says it owns *editorial* content, and a private support conversation is not
editorial. It would bend a clean module out of shape for storage convenience, and
the comment model would need per-comment internal/external visibility that
editorial comments have no use for.

**Recommendation: B for release, revisit A afterwards.** Support tooling is not
where a small team differentiates, and the module map has no room for it in the
release scope. Keep the KB in Content (cheap, clearly owned) and keep the
deep-link affordance as a Platform UI slot pointed at a configurable URL — the
same contract LanCore already ships, minus an application to maintain. If our own
events prove the context snapshot is worth more than an off-the-shelf tool gives
us, A is a clean post-release addition and nothing in B blocks it.

### D-EXT-02: LanShout's chat and where Reverb runs

**Context:** The chat feature itself is settled — Community's SPEC already claims
channels, messages, moderation state, mutes and bans, and LanShout's `FilterChain`
/ `ChatSetting` / timeout model drops straight in. The announcement half
evaporates (Content publishes; Community or a Platform UI slot renders). What is
genuinely open is the websocket process: Reverb is the one piece of LanShout that
is not just code, and Konventix has never run a long-lived socket server.

**Option A — Reverb as a fourth deployment role of the one image.** Web, workers,
scheduler, reverb. Pros: consistent with ADR-0002 and the deployment model
already documented in the overview; brand context, auth and channel authorisation
all come from the same application, so private per-brand channels are a policy
call rather than an integration; one image to build. Cons: a stateful process in
a stack that is otherwise stateless — its own scaling, connection limits, and
sticky-routing concerns; it makes Community's release-readiness partly a
deployment question; and Community is post-release, so this infrastructure would
sit unused for a while.

**Option B — polling instead of websockets for chat v1.** Community ships chat
over short-interval polling or Inertia's own refresh machinery; Reverb arrives
only if the load justifies it. Pros: zero new infrastructure; chat at a LAN of a
few hundred people is not a scale problem; matches "runtime separation only for
genuine offline requirements" in spirit — do not add a process until it earns
itself. Cons: worse UX for the one feature whose entire value is immediacy; the
polling implementation gets thrown away later; presence (also Community's) is
awkward without a socket.

**Option C — keep LanShout external as the realtime app.** Pros: no new
infrastructure inside Konventix; the code exists. Cons: this is precisely what
ADR-0002 was written against, and the evidence is damning — the app has no
Dockerfile in its tree, three silent contract mismatches with LanCore, duplicated
RBAC that has already diverged, a webhook verifier that fails open on an empty
secret, and its realtime is not even running in the public demo. Keeping it means
keeping a second identity system to sync forever, for a chat wall.

**Recommendation: A, but sequenced — build Community's chat against Laravel's
broadcasting abstraction and add the Reverb role when Community ships.** C is out
on ADR-0002. B trades a real UX loss for a deferral of work we will do anyway,
and presence makes it worse. Because Community is post-release, A costs nothing
now: the decision that matters today is only that Reverb is a *role of our image*
and not a separate application, so nobody designs around a second deployment.

### D-EXT-03: Where LanCore's outbound integrations actually belong

**Context:** The "Integration" domain does not integrate with anything. What
LanCore actually talks to lives elsewhere: `Tmt2Client` and `LanBracketsClient`
sit in an `Api` domain; the inbound LanBrackets webhook is verified inside
`Competition`; the connectivity probes for Stripe, PayPal, Steam, Listmonk and
TMT2 are one `ExternalApiTester` service in `Orchestration`, importing five
vendors. Meanwhile `Integration` owns only auth for *our own satellites*. The
naming and the ownership are unrelated to each other.

**Option A — Extensibility owns every external HTTP relationship, in and out.**
One module for anything crossing the process boundary. Pros: one place to audit
for the ADR-0011 licensing line; one place for retries, timeouts, secrets and
health checks. Cons: it recreates `ExternalApiTester` at module scale — a module
importing Stripe, Steam, Listmonk and TMT2 concepts is a cross-module god object
by construction, and its own SPEC forbids exactly this ("Anything that could be a
first-party module... is a module"). It also inverts direction: an outbound
driver is a private implementation detail of the module that needs it.

**Option B — direction decides ownership. Extensibility owns the *inbound*
external surface; each module owns its own *outbound* drivers.** Extensibility
owns OAuth apps, the versioned public API, scope mapping, and outbound webhook
delivery — the generic tier that knows no vendor. GameOps owns the TMT2 handler
driver, Competition the bracket provider driver and its inbound webhook, Commerce
the settlement adapters, Identity the social providers, Newsletter the Listmonk
client. Pros: it is what LanCore's *best* code already did — the LanBrackets
webhook is verified in Competition, not Webhook, and ADR-0012 already models TMT2
as a `MatchHandler` driver; each vendor concept stays inside one module boundary;
health checks become a small Platform contract each module implements. Cons: HTTP
plumbing (retry policy, timeout defaults, secret storage, log redaction) is
solved five times unless a shared Platform helper exists; "where is the Stripe
timeout configured" has five answers.

**Option C — status quo shape: a technical `Api` module holding all clients.**
Pros: clients are findable; shared HTTP conventions in one place. Cons: it is a
layer, not a module. A module boundary that groups by technology rather than by
ownership is exactly finding #1 in `lessons-from-lancore.md`, and it puts a
Competition concern (bracket sync) and a Commerce concern (settlement) behind one
door.

**Recommendation: B, with a thin shared HTTP-client helper in Platform.** The cost
of B is real but bounded and mechanical; the cost of A and C is a boundary
violation that compounds. Concretely: `Tmt2Client` → GameOps, `LanBracketsClient`
and the inbound bracket webhook → Competition, PSP probes → Commerce, Steam →
Identity, Listmonk → Newsletter, and `ExternalApiTester` becomes a `HealthCheck`
contract in Platform that each module implements — one Artisan command iterating
registered checks, keeping the EXT-F-001..005 exit-code contract (0 connected,
1 failed/unreachable, 2 not configured) which is genuinely worth preserving.

### D-EXT-04: Webhook delivery guarantees

**Context:** LanCore's delivery is one queued listener: `Http::timeout(10)->post()`,
`increment('sent_count')`, `$response->throw()` inside `try/finally`, and a
`webhook_deliveries` row with status code, duration and a boolean. No `$tries`,
no `$backoff`, no dedicated queue, no dead-letter path, and — decisively — **the
payload is never persisted**. The delivery job serialises the `Webhook` model and
an array; once the job fails out of the queue, the event is gone. Nothing can be
replayed, and nothing can tell a consumer they missed something. The requirement
(WHK-F-004) claims the response body is stored; the migration has no such column.
Konventix has to state a promise before it publishes an endpoint, because the
promise is the contract.

**Option A — best-effort, at-most-once, honestly documented.** Queue it, retry a
few times with backoff, log the attempt, drop it. Pros: cheapest; adequate when
webhooks are notifications-to-go-look rather than data transfer; no storage
growth. Cons: consumers cannot build state on it, which means every serious
integration also has to poll the API — so we end up owning both surfaces anyway,
and the webhook tier stops paying for itself.

**Option B — at-least-once with a durable outbox.** Persist the payload at
projection time, deliver on a dedicated queue with exponential backoff and a
capped attempt count, dead-letter on exhaustion, expose the delivery log with
manual and bulk replay, auto-disable a subscription after sustained failure.
Signature carries a timestamp and a version prefix so replay is distinguishable
from a live delivery and consumers can enforce a freshness window. Pros: the only
promise a consumer can actually build on; the delivery log becomes a support tool
("did they get it" has an answer, which Messaging's SPEC already treats as the
reason a delivery log exists); disabling a dead endpoint stops it poisoning the
queue. Cons: an outbox table that grows and needs retention (Compliance
territory); ordering is still not guaranteed, so payloads must be
self-describing and consumers idempotent; more moving parts in a module that
should stay a pipe.

**Option C — at-least-once with ordered per-subscription delivery.** B, plus a
serial queue per subscription so events arrive in order. Pros: removes the
hardest class of consumer bug. Cons: one slow endpoint blocks its own backlog
indefinitely; head-of-line blocking needs its own timeout policy; and ordering
across modules is not something a monolith with parallel workers can honestly
promise anyway — offering it partially is worse than not offering it.

**Recommendation: B, with the promise written down before the first endpoint
ships.** At-least-once, unordered, self-describing payloads carrying a
monotonic-per-subscription sequence number so consumers can detect gaps and
deduplicate; signature over `timestamp + body` with a key id to make rotation
possible; retention on the outbox set by Compliance policy. Delivery gets its own
queue name — the deployment model already splits workers by queue, and a slow
external endpoint must never share a worker with provisioning. C is a promise we
cannot keep; A quietly forces us to build a polling API as well.

### D-EXT-05: Where the AGPL plugin boundary is drawn technically

**Context:** ADR-0011 says proprietary plugins must talk to the core "only over
the out-of-process Extensibility tier or clearly separated module boundaries".
Those are two different lines, and the second is undefined. It matters because
`app-modules/*` composer packages loading into the same PHP process, using our
service container, our Eloquent base classes and our Data objects, are a
plausible derivative work under the AGPL — and the whole secondary revenue
stream sits on whichever answer we pick. The module's own SPEC lists this as
open. Deciding late is expensive: the answer shapes what a plugin API looks like.

**Option A — out-of-process only. A proprietary plugin is an external app.** It
authenticates via OAuth, reads and writes over the versioned public API, and
receives webhooks. Nothing proprietary is ever loaded into our process. Pros: the
cleanest licensing line available, and the one no lawyer argues about; it is the
same tier third parties already use, so it is tested by real usage rather than
invented for one customer; it makes the AGPL genuinely safe to enforce. Cons:
plugins cannot add UI to our pages, cannot add a nav entry, cannot add a database
table, cannot subscribe to internal events synchronously — which rules out most
of what people actually want to sell; and it hard-blocks all plugin revenue
behind Extensibility shipping, i.e. behind release plus internal-contract
stability.

**Option B — an in-process plugin API behind a stable, explicitly-licensed
contract package.** A separate, permissively-licensed `konventix/plugin-contracts`
package (interfaces, DTOs, extension-point registrations) that proprietary code
may depend on; the AGPL core implements those interfaces. Pros: plugins get nav
entries, UI slots, block types, listeners and their own tables — the things worth
paying for; it matches how the ecosystem already reasons about this pattern.
Cons: the licence exception has to be drafted deliberately and is only as strong
as its wording; every extension point becomes a permanent public API, which is a
maintenance obligation on a 1–3 person team; and it is easy for a plugin to reach
past the contract into core internals, at which point the line exists only on
paper.

**Option C — both, tiered.** Out-of-process for third parties; in-process
first-party-only, with our own commercial modules licensed separately but built
by us. Pros: nobody else's proprietary code enters our process, so the AGPL line
stays crisp, while our own paid modules can be deeply integrated; sole
copyright (ADR-0011) means we may license our own code however we like. Cons: no
third-party plugin ecosystem, so "proprietary plugins" as a revenue line means
*our* plugins only; it looks like open-core favouritism and will read that way to
contributors.

**Recommendation: C now, with A as the published third-party path and B revisited
only if a concrete customer pays for it.** Sole copyright makes C legally free and
immediately usable — it is the only option that produces revenue before
Extensibility ships. A is the correct public answer and needs no new machinery
beyond the tier we are building anyway. B is the expensive one: a permanent
public API surface and a bespoke licence exception, and it should be bought, not
speculated on. Whichever way this goes, write it down as an ADR before the first
extension point exists — retrofitting a licence boundary onto shipped interfaces
is not possible.

### D-EXT-06: How external apps get registered

**Context:** LanCore registers apps two ways: an admin UI, and
`config/integrations.php` reconciled by `integrations:sync`. The config file lists
four apps by name, is populated from env by a Helm chart, and on every
reconciliation **deletes and recreates** each configured app's tokens and webhook
rows — which is why INT-F-014 exists at all, to let operators correlate "all my
tokens died" with a deploy. Konventix is brand-scoped (ADR-0010) and expects apps
registered by people we do not know, which the file model cannot express.

**Option A — database-only, registered through the UI, per brand.** An admin
creates an app, gets a client id and a secret shown once, manages tokens and
subscriptions as data. Pros: correct for third parties; correct for brand
scoping; no deploy-time credential handling; nothing to reconcile. Cons: no
reproducible provisioning — a fresh environment needs someone to click; automated
tests and demo seeding need a factory or a command; operators running many brands
have no bulk path.

**Option B — database-only, plus Artisan commands for provisioning.** A, plus
`apps:create` / `apps:issue-token` / `apps:revoke` returning machine-readable
output. Pros: keeps the DB as the single source of truth while making
environments reproducible from a script the operator owns; INT-F-010 already
proved these commands are useful; demo seeding and CI get a supported path. Cons:
the operator writes the script; slightly more surface to keep working.

**Option C — keep a declarative manifest, but idempotent.** A config file that
upserts and never destroys, with credentials referenced from a secret store.
Pros: GitOps-shaped, which suits Helm deployment; some operators genuinely want
apps declared alongside the deployment. Cons: it re-imports the model that made
tokens deploy-fragile in the first place; brand scoping fits badly into a global
file; and it is machinery built for a scenario (our own satellites needing seeded
credentials) that ADR-0002 has already deleted.

**Recommendation: B.** Delete the config file and the reconciler entirely; keep
and generalise the Artisan commands. The reconciler solved a problem that no
longer exists — bootstrapping four in-house apps at deploy time — and its
delete-and-recreate semantics were a genuine operational hazard. If GitOps
declaration is ever asked for, it can be a thin script over B's commands rather
than a second write path into the same tables.

### D-EXT-07: Does the offline entrance client use the Extensibility tier?

**Context:** ADR-0002 names the venue entrance client as *the* legitimate
runtime-separation exception. Admission's SPEC already claims the entrance API
and the offline client, and lists the offline validation model as an open
question. Extensibility's SPEC asks whether the entrance client should use its
tier and notes it "predates this module and probably should not wait for it".
LanCore answered by accident: `/api/entrance/*` sits in the same route file as
`/api/integration/*`, behind the same `integration.auth` middleware, and
LanEntrance was a registered integration app with a config block and a client
sub-package (ICLIB-F-006). So today the door depends on the extension tier.

**Option A — the entrance client is an external app on the Extensibility tier.**
Pros: one authentication mechanism, one token model, one audit path; the door
client becomes proof that the external API is good enough for a real consumer.
Cons: it makes a release-scope, safety-critical capability depend on a
post-release module — the entrance API must ship in Phase 2 and Extensibility
does not. Also wrong in principle: a first-party device we deploy is not a
third-party integration, and its scopes would be nothing like an app's.

**Option B — a dedicated internal channel owned by Admission.** Device
enrolment, device credentials, the sync/validate endpoints and the signing-key
distribution all belong to Admission, authenticated by a device credential, not
an OAuth app. Pros: unblocks Phase 2 completely; keeps the offline model, PII
limits and conflict resolution — Admission's three open questions — inside the
module that owns the answers; a door device has a lifecycle (enrol, rotate,
revoke, wipe) that an OAuth client does not. Cons: a second authentication
mechanism in the codebase; some duplicated token plumbing; a risk that "internal
API" quietly becomes a general-purpose one.

**Option C — build the transport tier early, just for the entrance client.**
Pros: one mechanism, and it ships when it is needed. Cons: it violates the
explicit gate — publishing an external contract before internal ones are stable —
for a consumer that is not external, and it would freeze the tier's design around
one very unusual client.

**Recommendation: B, unambiguously.** Admission owns the entrance API and the
device credential; Extensibility, when it arrives, may reuse Admission's
device-enrolment shape or not, but the door must never wait on it. Write this
into both SPECs — it closes an open question in each, and it keeps the one
sanctioned runtime separation from being justification for a second one.

### D-EXT-08: Does server-to-server user lookup survive?

**Context:** `POST /api/integration/user/resolve` lets any app holding a valid
token resolve **any** user by id or e-mail address, returning name, e-mail, roles,
locale, avatar and profile URL, filtered only by the app's coarse scopes. There
is no per-user grant, no consent, no audit record, and no rate limit beyond the
route default. It exists because satellites needed to map a webhook payload onto
a local shadow user. With no satellites, the justification is gone — but a real
external app will eventually want the same thing, and it is also an e-mail
enumeration oracle.

**Option A — drop it. Identity data flows only through the authorization-code
flow.** An app learns about a person when that person authorises it. Pros:
removes the enumeration oracle entirely; matches how OAuth providers behave;
smallest GDPR surface. Cons: an app that receives a webhook about a user it has
never seen cannot resolve them — so webhook payloads must carry enough identity
inline, which pushes PII into payloads that get retried, logged, and stored in
the delivery outbox. That may be worse.

**Option B — keep lookup, but only for users who have authorised that app.** The
app may resolve a ULID it was previously granted; unknown ids return 404,
identically and in constant time. E-mail lookup is removed. Pros: kills
enumeration; keeps the legitimate webhook-resolution case; makes the grant the
unit of authorisation, which is what it should be. Cons: needs a grant table and
its lifecycle (revoke on user request, cascade on app deletion); apps that
integrate with a *brand's* whole audience rather than individual users are not
served.

**Option C — keep it as-is, behind a privileged scope.** Pros: cheapest; some
operator-owned integrations legitimately need a directory view. Cons: a scope is
just a flag someone will set; the enumeration risk is unchanged for any app that
has it, and "operator-owned" is not a distinction the token model can enforce.

**Recommendation: B, with C's directory case handled as an explicitly separate,
operator-granted scope that is audited per call.** Webhook payloads should carry a
stable ULID and nothing more; resolution against a grant is how an app turns that
into a person. E-mail lookup does not come back in any form.

### D-EXT-09: What a Sponsorship is attached to, and whether it knows about money

**Context:** LanCore models this as `Sponsor -> SponsorLevel` plus four
timestamp-only pivots (events, programmes, time slots, users), rendered as a
hardcoded logo grid on `Welcome.vue`. There is no period, no display weight, no
link to anything commercial, and no placement model — the landing page simply
knows about sponsors. The module SPEC's open questions ask whether sponsorship is
brand-level or event-level, whether tiers are free-form, and whether a sponsor
ever links to a Commerce customer. These are one decision, because the answer to
"what does a Sponsorship attach to" determines the rest.

**Option A — keep LanCore's shape: sponsor belongs to a tier, pivots to events.**
Pros: smallest port; the data migrates one-to-one; fine for a brand with one
sponsor list per year. Cons: a sponsor cannot be gold at one event and silver at
another without duplicating the sponsor; there is nowhere to put the active
period, so "which sponsors show on the 2026 page" is answered by hand; and the
tier is a property of the organisation rather than the deal, which is simply
wrong.

**Option B — `Sponsorship` as a first-class entity: sponsor + scope (brand or
event) + tier + period + weight.** The sponsor is a durable public profile; the
sponsorship is the yearly deal. Placement is a Sponsoring-registered block type
that queries active sponsorships for the current scope. Pros: answers every one
of the SPEC's open questions with one model; inheritance falls out (a brand-level
sponsorship applies to every event unless an event-level one overrides); the
landing page stops knowing about sponsors, which is what ADR-0008 and the block
CMS are for. Cons: a slightly bigger model than "thin"; the brand-vs-event
override rule needs to be written down and tested or it becomes folklore.

**Option C — B, plus a ULID link from Sponsorship to a Commerce order or
invoice.** Pros: "which sponsors have paid" becomes answerable, which is the
first question anyone asks in practice. Cons: it drags Commerce into a
post-release module and invites Sponsoring to grow opinions about dunning, which
its SPEC explicitly forbids; and a sponsorship is frequently barter (they supply
the hardware) with no invoice at all, so the link is optional in a way that makes
it a poor structural dependency.

**Recommendation: B, with a nullable `commerce_reference` ULID that Sponsoring
stores and never interprets.** B is what makes the module correct rather than
merely small, and the cost over A is one table. The nullable reference gives
Commerce something to join on when someone eventually builds a "sponsorship
revenue" report, without Sponsoring knowing what an invoice is — which is exactly
the ADR-0004 pattern. Explicitly deferred until the module is built: sponsor
self-service, programme and time-slot placements, and where logos are stored
(unsettled codebase-wide, not a Sponsoring decision).
