# Support requests: the feedback button, the report, and the process behind it

Date: 2026-09-21. Status: approved design (operator decisions 2026-09-21, recorded in §14), awaiting spec review.
Issues: #88 (umbrella, Feedback- und Fehlermeldefunktion); slices #304 (S0 Foundation), #305 (S1 Report channel), #306 (S2 Console), #307 (S3 Reporter access), see §15. Neighbours: #89 (correlation id, closed), #96 (alerting, "Kauf betroffen" on the alert channel), #97 (support address per brand), #48 (full Support module after the event), #122 (tracing, stays open).
Decisions: ADR-0022 (Support module), ADR-0063 (Support is always on — amends ADR-0022), ADR-0056 (correlation id), ADR-0059 (metrics, dashboards, alerts).

## 1. Goal

Every page of Konventix — brand hosts and the operator console alike — carries a small floating button. It opens a minimal dialog: what kind of problem, a few sentences, and for a visitor without an account an e-mail address. Submitting it stores a `SupportRequest` that already knows everything the person cannot be expected to tell us: the correlation id of the page and of the submission, the route, the release, the brand, the account, the previous page, the browser and viewport. Operators see new requests in the console and by mail, a "purchase affected" report additionally raises an out-of-band alert, and the reporter can follow the request — signed in on "My support requests", or as a guest through a mailed link — until it is resolved. Everything is GDPR-exportable and has a retention clock.

This is issue #88 in full, not a cutover-week subset. The slices in §15 order the work so the report channel itself lands first.

## 2. Facts the design rests on

Verified 2026-09-21 on `develop` (d957126 merged, tag v0.6.5) and in GitLab.

- `app-modules/support/` exists as a scaffold: `SupportServiceProvider` with `moduleName() = 'support'`, every `src/*` directory a `.gitkeep`, `routes/web/support.php` empty, `SPEC.md` all TODO. The architecture test already counts it among the 19 providers. It is gateable by default (`ModuleServiceProvider::$gateable = true`).
- `ModuleGate::isActive()` returns **false without a brand in context**. The operator console runs without a brand. A gateable Support could therefore never render its button on the admin host through `SlotRegistry`; ADR-0063 makes Support non-gateable (like Compliance) so the report channel is a property of the platform, not a brand feature.
- The correlation id (ADR-0056) is done: `App\Observability\CorrelationId` (ULID or adopted `X-Request-ID`, `[A-Za-z0-9._-]{8,64}`), `AssignCorrelationId` first on the global stack, echoed on every response, restored in queue jobs, shared as the Inertia prop **`correlationId`** (`HandleInertiaRequests.php:169`, comment names #88), printed as "Reference" on the error pages. `RequestContext` already puts `release`, `route`, `brand` (slug) and `user_id` (ULID) into every log record. **There is no trace id and there will be none** (ADR-0056 §5, ADR-0059 §9): "session information" for a report means the correlation ids, and the runbook's "Finding a purchase" pastes exactly that into the `konventix-logs` dashboard.
- `config('app.version')` is the deployed image tag (`APP_VERSION`, default `dev`); the commit sha is not available inside the container.
- Shells: `resources/js/layouts/AppTopbarLayout.vue` (brand hosts) and `AdminLayout.vue` (console). Both already mount registry-resolved chrome: `AdminLayout` renders `resolveRegisteredComponent('platform/dialogs/cookie-settings')` and skips silently when nothing ships under that name; open state lives in a module-level `ref` composable (`useCookieSettingsDialog`). `resolveRegisteredComponent()` resolves `<module>/<path under resources/js/components>` from any module without a host-side map.
- Dialog primitives are shadcn-vue on reka-ui (`components/ui/dialog`); toasts are vue-sonner (`Toaster` in both layouts, `flashToast` for server flashes). **No `textarea` primitive exists.** No i18n library: UI copy is literal English; mail templates carry `de`/`en` bodies through Messaging's `TemplateRegistry`.
- Forms: spatie/laravel-data object per endpoint with `rules()` (ADR-0009), an Action class per write (ADR-0015), thin controller. A JSON post from a dialog that must not navigate uses Inertia v3's `useHttp` (precedent: `components/admin/MediaPicker.vue`). Wayfinder generates typed route helpers.
- Rate limiting: named limiters in the owning provider (`RateLimiter::for`), `throttle:<name>` on the route, plus a second axis in the controller keyed on `sha1($email)` (`PublicDataRequestController`). No captcha anywhere.
- Guest flows with mailed links: `DataSubjectRequestVerification` (token table, 24 h) and `IssueAdmissionClaimLink` (`URL::temporarySignedRoute`). Both patterns exist; this design uses signed routes (§8).
- Messaging (ADR-0041, ADR-0048): a module declares `Topic`s and `MessageTemplate`s in its provider and calls `SendMessage::handle(new SendMessageRequest(recipientId, templateKey, payload, recipientEmail, recipientLocale, brandId, attachments, topicKey, urgent))`. `recipientId: null` plus `recipientEmail` reaches a stranger. Channels: `mail`, `in_app` (the bell, `messaging/NotificationBell`), `push`. Commerce's `commerce.operations` topic and `commerce.operations-notice` template are the pattern for operator notices; the topic key belongs to Commerce and is not reused.
- Operator addressing: `PermissionHolders::userIdsWithPermission($brandId, $permission)` and `OperatorSettings::adminBaseUrlForBrand($brandId)` for absolute console links (both used by `NotifyOperatorsOnPaymentFailed`).
- Authorization: `src/Enums/Permission.php` per module (ADR-0013), `defaultRoles()` per module (ADR-0045), `AuthorizingAction` for writes (ADR-0015). Admin routes in `routes/admin/*.php` are wrapped by the provider in `web` + operator host + `auth` + `can:platform.access-admin`; a `NavigationItem` with `AREA_ADMIN` and a permission adds the sidebar entry, `AREA_ACCOUNT` adds one to the account menu (rendered only with a brand and a user).
- GDPR: `GdprHandler` (`module()`, `exportFor(SubjectRef)`, `delete(SubjectRef, DeletionMode)`) registered from `registerGdprExporters()`; a subject spans the operator, queries drop the brand scope (ADR-0050). `AuditRecorder::record()` is the explicit audit write for privileged reads.
- Observability (ADR-0059, `.ai/rules/observability.md`): `src/Metrics/<Module>Metrics.php` catalogue, `src/Listeners/Metrics/` recorders on the module's own events, panel in `contrib/observability/src/*.mjs`, rule with unit test in `contrib/observability/alerts/`, runbook anchor in `docs/operations.md`; `ObservabilityCoverageTest` enforces the mechanical half. Labels are bounded sets.
- Schedule: `registerSchedule()` with `callAfterResolving(Schedule::class, …)` and `withoutOverlapping()` (Resources' hold sweep).
- Timeline: cutover to production is Tue 2026-09-22 (#157); Phase 4 Hypercare runs 24.09–30.09. Issue #88 is labelled unverzichtbar and Go-Live but has no code yet.

## 3. Architecture

```
Brand host page / console page (any layout)
  <SupportLauncher/>  ──── floating button, resolved by name 'support/SupportLauncher'
        │ open()            state: useSupportDialog() (module-level ref)
        ▼
  <SupportRequestDialog/>   categories · message · (email) · consent · "what we send"
        │ useHttp POST  support.requests.store  (brand host)
        │               admin.support.requests.store (operator host)
        │ body: SupportRequestInputData + SupportClientContextData
        ▼
  SupportRequestController::store ── throttle:support-report, honeypot, CaptchaVerifier (null today)
        │
        ▼
  OpenSupportRequest (Action)  ─── writes support_requests (+ SupportContextData cast)
        │                          emits SupportRequestOpened
        ├─► RecordSupportMetrics            support_requests_total{brand,category}
        ├─► NotifyOperatorsOnRequestOpened  topic support.requests → in_app + mail (urgent)
        └─► AcknowledgeReporter             mail (guest: signed link, 30 d) / in_app + mail (account)

  Console  /admin/support-requests (index · show · state · known issue · reply · external ref)
  Reporter /my/support-requests (account)  ·  /support/requests/{id}?signature (guest)
  Out of band: vmalert KonventixSupportPurchaseAffected on the counter → Alertmanager (same channel as #96)
```

The floating button and the dialog are host chrome contributed by the module: both layouts render `resolveRegisteredComponent('support/SupportLauncher')` exactly as `AdminLayout` renders the cookie dialog today, and render nothing if the component is absent. ADR-0022's "Platform UI slot for ask-about-this" remains the mechanism for *other modules* to hand a typed reference into a request (an order page saying "help with this order"); it is not needed for the launcher and ships later (§16).

## 4. Domain model

All ids ULIDs (ADR-0014). All tables in `app-modules/support/database/migrations`.

### 4.1 `support_requests`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | Human reference = last 8 characters, upper-cased (`referenceShort`), the same convention Commerce uses for orders. |
| `filed_on_brand_id` | ulid, nullable, FK brands | Provenance only (ADR-0050 §1): which brand host the report came in on; `null` from the operator console. **Not a query scope** — the model does not use `BelongsToBrand`; the console lists across brands and filters by this column. |
| `user_id` | ulid, nullable | The signed-in reporter. Nulled by the GDPR handler. |
| `email` | string(255), nullable | Required when `user_id` is null. Lower-cased. Nulled by the GDPR handler. |
| `contact_consent` | bool | "You may contact me about this". Without it, no reply mail is sent; the acknowledgement with the access link is still sent to a guest because it *is* the access. |
| `categories` | jsonb, cast `list<SupportCategory>` | Multi-select, at least one. |
| `message` | text | 10–4000 characters. |
| `state` | string, enum `SupportRequestState` | `received` → `seen` → `in_progress` → `resolved` \| `wont_fix`; `seen` is set by the first console open, the rest by staff. |
| `is_known_issue` | bool | Set by staff; marks the request as the canonical instance of a known problem. |
| `known_issue_notice` | string(500), nullable | The text shown in the dialog's banner while `is_known_issue` and state is not terminal. |
| `external_reference` | string(255), nullable | Reserved for GitLab (#88: empty today, no migration later). Free text, e.g. `konventix/konventix#123`. |
| `subject_module` / `subject_id` | string(40) / ulid, both nullable | ADR-0022's typed reference, reserved now, filled by the slot mechanism later (§16). |
| `context` | jsonb, cast `SupportContextData` | §5. |
| `assigned_to_user_id` | ulid, nullable | Staff assignment (ADR-0022 names it); optional in the console. |
| `seen_at`, `resolved_at`, `closed_at` | timestamps, nullable | `closed_at` is set on `resolved` and `wont_fix`; the retention clock reads it. |
| `created_at`, `updated_at` | timestamps | |

Indexes: `(state, created_at)`, `(filed_on_brand_id, created_at)`, `(user_id)`, `(email)`, partial on `is_known_issue = true`.

### 4.2 `support_request_messages`

| Column | Notes |
|---|---|
| `id` | ulid |
| `support_request_id` | FK, cascade |
| `author_user_id` | nullable; null = the guest reporter (identified by the signed link) |
| `visibility` | enum `MessageVisibility`: `external` (reporter sees it) \| `internal` (staff note) |
| `body` | text, 1–4000 |
| `created_at` | |

The reporter's original text stays in `support_requests.message`; messages are the thread after it. A reporter (account or guest link) may add `external` messages while the request is not terminal.

### 4.3 Enums

- `SupportCategory`: `purchase_affected`, `visibility`, `function`, `display`, `suggestion` — the five from #88, in that order in the UI.
- `SupportRequestState`: `received`, `seen`, `in_progress`, `resolved`, `wont_fix`. Transitions enforced in `TransitionSupportRequest`: `received→seen` (automatic), `seen→in_progress|resolved|wont_fix`, `in_progress→resolved|wont_fix`, `resolved|wont_fix→in_progress` (reopen, staff only).
- `MessageVisibility`: `external`, `internal`.

## 5. Context: what is captured, and who is trusted for what

Two ids, because the page that misbehaved and the POST that reported it are two requests:

| Field in `SupportContextData` | Source | Trust |
|---|---|---|
| `page_correlation_id` | client, from `usePage().props.correlationId` | untrusted; validated against `CorrelationId::ACCEPTABLE`, else null |
| `submit_correlation_id` | server, `CorrelationId::current()` of the POST | authoritative |
| `url` | client, `window.location.href` (query kept, hash dropped) | untrusted, max 2048 |
| `route` | server, the route name of the page, derived from the URL via the router if resolvable, else null | server |
| `previous_url`, `previous_correlation_id` | client, from the visit ring buffer (§6.3) | untrusted, validated as above |
| `recent_visits` | client, last 5 `{url, correlation_id, at}` | untrusted, bounded |
| `release` | server, `config('app.version')` | server |
| `commit` | server, `config('app.commit')` — **null until the chart passes a build sha** (flagged, §14) | server |
| `brand` | server, current brand slug or null on the console | server |
| `event` | server, the `event` route parameter of the reporting page if the URL resolves to a route that has one (slug), else null | server |
| `user_id`, `locale`, `timezone` | server, from the authenticated user | server |
| `user_agent` | server, request header, truncated to 512 | server |
| `viewport` | client, `{width, height, dpr}` | untrusted, integers |
| `client_sent_at` | client, ISO timestamp | untrusted |
| `received_at` | server | server |
| `last_client_error` | client, the last `window.onerror`/`unhandledrejection` message and stack captured by the launcher (ring of 1), if any | untrusted, max 2000 |

Rules: the server never trusts a client field it can derive itself; the client never sends form contents of the page, cookies, local storage, or a screenshot (#88: "Screenshots und Formularinhalte werden nicht automatisch mitgeschickt"). No IP address is stored anywhere — the limiter keys hash it and forget it. The dialog shows the person what goes along ("What we send with your report", collapsed, listing the fields above by name and the page correlation id verbatim).

## 6. Submission

### 6.1 The launcher

`app-modules/support/resources/js/components/SupportLauncher.vue`: a fixed-position button, bottom-right, `z-index` below the toaster and below the cookie banner; hidden while the cookie banner is open (it reads the same `cookies.declared` prop the banner does) and on print. Icon plus visually hidden label "Report a problem or give feedback"; `aria-haspopup="dialog"`, `aria-expanded`. Keyboard: focusable, Enter/Space opens; the dialog traps focus and returns it on close (reka-ui does both). Touch target ≥ 44 px (ADR-0055). On the console it sits in the content area's corner, not over the sidebar.

Mounted from both layouts:

```vue
const supportLauncher = resolveRegisteredComponent('support/SupportLauncher');
…
<component :is="supportLauncher" v-if="supportLauncher" />
```

`useSupportDialog()` (module-level `ref`, `open()`, `close()`, `openWithReference(module, id)` for later slot use) lets any page open the same dialog.

### 6.2 The dialog

`SupportRequestDialog.vue`, one `Dialog` from `components/ui/dialog`, content in this order:

1. Title "Report a problem or give feedback"; one-line description.
2. Known-issue banner (§9.4), only when the brand has an active one.
3. Category chips, multi-select (`role="group"`, each a toggle button with `aria-pressed`): Purchase affected · Visibility · Function · Display · Suggestion. At least one required.
4. Message `Textarea` (new primitive `components/ui/textarea`, added in S1), 10–4000 characters, counter.
5. E-mail input — guests only; pre-filled and read-only for accounts (display only, not posted).
6. Consent checkbox "You may contact me about this report" (unchecked by default; for a guest, a hint that the confirmation mail with the access link is sent regardless).
7. Collapsible "What we send with your report" listing §5 and the page correlation id.
8. Honeypot input (`website`, visually hidden, `tabindex=-1`, `autocomplete=off`).
9. Footer: Cancel · Send.

Submit through `useHttp` (JSON, never an Inertia visit, so the page underneath is untouched). 422 renders field errors inline (`InputError`); 429 renders "Too many reports, please try again in a few minutes"; success closes the dialog and toasts "Thanks — reference ABCD1234. We sent a confirmation to you@example" (guest) or "… You can follow it under My support requests" (account) with a link.

Copy is English, literal strings, like the rest of the UI (flagged for a later i18n pass, §14). Mail templates are `de`/`en`.

### 6.3 Client context and the visit ring buffer

`useSupportContext()` (module composable, initialised by the launcher once per page load): keeps the last five Inertia visits as `{url, correlation_id, at}` by listening to Inertia's `success` event and reading `usePage().props.correlationId` after each visit; registers `window.onerror` and `unhandledrejection` handlers that keep only the *last* message and stack (no reporting, no network). `snapshot()` returns the client half of `SupportContextData`. Nothing persists beyond the page load.

### 6.4 Endpoint, limits, abuse

Routes (bare, wrapped by the provider):

- `routes/web/support.php`: `POST /support/requests` → `support.requests.store` (brand host; guests allowed).
- `routes/admin/support.php`: `POST /support/requests` → `admin.support.requests.store` (operator host; always an authenticated operator). Same controller, same Action; the launcher chooses by `usePage().props.admin !== null`.

Both carry `throttle:support-report`. Limiter in `SupportServiceProvider::registerRateLimiters()`: `Limit::perMinutes(10, 5)->by($user?->id ?? $request->ip())`. Second axis for guests in the controller: 3 per hour per `sha1($email)`. A non-empty honeypot answers **201 with a fake reference** and stores nothing (a bot that gets a 422 learns; one that gets a 201 does not). `CaptchaVerifier` contract (`verify(Request): bool`) bound to `NullCaptchaVerifier`; `config/support.php` `captcha.driver = null|turnstile` so a Turnstile driver can be dropped in without touching the controller (operator decision 2026-09-21: keep an optional captcha in mind).

Validation lives in `SupportRequestInputData::rules()` (categories, message, email, consent, honeypot) and `SupportClientContextData::rules()` (bounded lengths, id shape). Authorization: `OpenSupportRequest` is *not* an `AuthorizingAction` — filing a report needs no capability; it is the one public write of the module, like Compliance's public data request.

`OpenSupportRequest::handle(SupportRequestInputData, SupportContextData, ?string $userId, ?string $brandId): SupportRequest` writes the row inside a transaction and dispatches `SupportRequestOpened` after commit.

## 7. Notifications

Support declares two topics and five templates in `registerMessageTopics()` / `registerMessageTemplates()`:

| Topic | Label | Channels | Suppressible | Digestable | Audience |
|---|---|---|---|---|---|
| `support.requests` | Support requests | in_app, mail | yes | yes (sends are `urgent`) | holders of `support.triage-requests` on the brand; on a console-filed request, holders on the operator's brands with `platform.access-admin` — *first slice: every operator with `support.triage-requests` on any brand* |
| `support.request-updates` | Updates on my support requests | in_app, mail | yes | no | the reporter (account) |

| Template | To | When |
|---|---|---|
| `support.request-received` | reporter | on `SupportRequestOpened`. Guest: contains the signed access link (§8) and the reference. Account: reference and link to `/my/support-requests/{id}`. Sent regardless of `contact_consent` — it is the receipt and, for a guest, the only key to the request. |
| `support.request-notice` | operators | on `SupportRequestOpened`, `urgent: true`; `title` = "New support request ABCD1234 · Purchase affected, Function", `detail` = first 200 characters of the message plus brand, route, release; `url` = absolute console link. One template, branch-free (Commerce's `operations-notice` shape). |
| `support.request-state-changed` | reporter | on `SupportRequestStateChanged` to `in_progress`, `resolved`, `wont_fix` — only if `contact_consent` (the receipt already told them how to check). |
| `support.request-replied` | reporter | on `SupportRequestMessagePosted` with `external` visibility, only if `contact_consent` |
| `support.request-link` | guest reporter | on link re-issue (§8.2) |

Operators receive the mail **in addition to** the out-of-band alert: the alert is for the person on call, the mail and the bell are the in-application process (operator decision 2026-09-21, item 10). All sends are best-effort with `Log::warning` on failure; a failed notification never fails the request.

`purchase_affected`: no separate code path in Messaging — the notice title carries the category and the counter carries the label; vmalert does the rest (§10.6).

## 8. Reporter access

### 8.1 Accounts

`GET /my/support-requests` (index: reference, categories, state, created, last update) and `GET /my/support-requests/{request}` (the request, the context the person sent, the external thread, a reply form while not terminal). `NavigationItem` with `AREA_ACCOUNT`, label "My support requests". Policy `SupportRequestPolicy::view` = `user_id === actor`. Reply: `POST /my/support-requests/{request}/messages` → `PostSupportMessage` (external, author = user).

### 8.2 Guests

The receipt mail carries `URL::temporarySignedRoute('support.requests.show', now()->addDays(30), ['request' => $id])`. The route is `signed`-middleware guarded and shows the same page as 8.1 in the brand layout (no account required, no session state written). Replying works on the signed URL too (`POST …/messages`, also signed, the form re-posts to the URL it came from).

An expired or tampered signature does **not** 403: the route's `signed` failure is caught by a small middleware that renders `support/LinkExpired` with the reference and one button "Send me a new link". That posts to `POST /support/requests/{request}/link` (`throttle:support-link`, 3 per hour per request id), which sends `support.request-link` with a fresh 30-day signed URL to the **stored** address — the page never asks for or reveals the address. A request that has been purged (§11) answers 404 there. Nothing else can revoke a link inside its 30 days; the link grants read and reply on one request only.

A guest whose address later gets an account is not migrated automatically (the export handler answers by `user_id` *and* by e-mail, so the person still gets their data; §10.5).

## 9. Console

### 9.1 Inbox

`GET /admin/support-requests` — table (`useDataTable`, the messages inbox pattern): reference, categories (chips), state, brand, release, reporter (name or "guest"), created, assignee, known-issue marker. Filters: state (default: open = received+seen+in_progress), category, release, brand, known issue. Sort by created desc. Permission `support.view-requests`.

### 9.2 Detail

`GET /admin/support-requests/{request}` — the message; the full `context` rendered as a definition list with **copy buttons on both correlation ids and a deep link into the `konventix-logs` dashboard** ("Find in logs", URL template from `config/support.php` `logs.correlation_url`, empty = no button); the thread with internal notes and external replies; the reporter's contact (masked unless `support.view-reporter-contact`). Opening it as the first staff member moves `received → seen` (`TransitionSupportRequest`, automatic, audited as `support.request.seen`).

Actions on the page, each its own Action class and route:

| Route | Action | Capability |
|---|---|---|
| `PATCH …/state` | `TransitionSupportRequest` | `support.triage-requests` |
| `PATCH …/assignee` | `AssignSupportRequest` | `support.triage-requests` |
| `PATCH …/known-issue` | `MarkKnownIssue` (flag + notice text) | `support.triage-requests` |
| `PATCH …/external-reference` | `SetExternalReference` | `support.triage-requests` |
| `POST …/messages` | `PostSupportMessage` (internal or external) | `support.triage-requests` |

Every write emits its event and is recorded in the audit trail through the Compliance wildcard subscriber (the events implement `AuditableEvent`), the same way Registration's admin actions are.

### 9.3 Navigation

`NavigationItem(module: 'support', label: 'Support requests', url: '/admin/support-requests', icon: 'life-buoy', order: 40, permission: 'support.view-requests', area: AREA_ADMIN)`. The sidebar entry shows the open count as a badge when the registry supports badges; otherwise none (flagged).

### 9.4 Known-issue banner

`KnownIssues::activeFor(?string $brandId): list<KnownIssueData>` — requests with `is_known_issue = true`, state not terminal, `filed_on_brand_id` equal to the brand or null (operator-wide), ordered by `updated_at` desc, cached for 60 seconds. The dialog's `SupportLauncher` receives them as a prop from `HandleInertiaRequests`? **No** — the shared props stay lean. The dialog fetches `GET /support/known-issues` (JSON, cached, no auth) when it opens; a banner per notice, dismissible per page load. A person may still file a report; the banner says "We know about: … You do not need to report it again, but you can add details."

## 10. Module wiring

### 10.1 Permissions and roles

`Konventix\Support\Enums\Permission`:

- `ViewRequests = 'support.view-requests'`
- `TriageRequests = 'support.triage-requests'` (state, assignment, known issue, external reference, messages)
- `ViewReporterContact = 'support.view-reporter-contact'`

`defaultRoles()`: `support.desk` "Support Desk" (all three). Brand Admin gets everything by ADR-0045's expansion. `permissions:sync` materializes them for every brand — Support is always active, so brand creation does too.

### 10.2 Activation

Support is **not gateable** (ADR-0063): `SupportServiceProvider::$gateable = false`, routes carry no `ensure-module`. Consequence for the console-filed request: `filed_on_brand_id` is null and `ModuleGate::isActiveForBrand()` is never consulted.

### 10.3 Routes

Web (brand host): `POST /support/requests`, `GET /support/known-issues`, `GET /support/requests/{request}` (signed), `POST /support/requests/{request}/messages` (signed), `POST /support/requests/{request}/link`; `auth`: `GET /my/support-requests`, `GET /my/support-requests/{request}`, `POST /my/support-requests/{request}/messages`.
Admin (operator host): `POST /support/requests` (the console launcher), and the console routes of §9.

### 10.4 Events (public contract)

| Event | Payload |
|---|---|
| `SupportRequestOpened` | `requestId`, `brandId?`, `userId?`, `categories: list<string>`, `release` |
| `SupportRequestStateChanged` | `requestId`, `brandId?`, `from`, `to`, `actorUserId?` |
| `SupportRequestMessagePosted` | `requestId`, `brandId?`, `messageId`, `visibility`, `authorUserId?` |
| `SupportRequestKnownIssueMarked` | `requestId`, `brandId?`, `isKnownIssue`, `actorUserId` |

Scalars only (ADR-0004). Support consumes nothing from other modules in this design.

### 10.5 GDPR

`SupportGdprHandler`: export = every request where `user_id = subject` **or** `email = subject email` (guest reports are personal data too), with message, categories, state, timestamps, context minus `user_agent`, and the person's own external messages. Delete (both modes): null `user_id` and `email`, replace `message` and the person's message bodies with `[removed on request]`, drop `context.user_agent`, `context.viewport`, `context.recent_visits` and `context.last_client_error`; keep the row (it is the operator's incident record). Idempotent.

### 10.6 Observability (ADR-0059)

`SupportMetrics`:

- `support_requests_total{brand, category}` counter — one increment per selected category on `SupportRequestOpened` (`brand` = slug or `console`).
- `support_request_transitions_total{brand, state}` counter on `SupportRequestStateChanged`.
- `support_requests_open{brand}` gauge (received+seen+in_progress) and `support_requests_unseen_oldest_age_seconds` gauge, at scrape time.

Panels: a "Support" row in `contrib/observability/src/platform.mjs` (requests by category, open by brand, oldest unseen age). Alerts:

- `KonventixSupportPurchaseAffected` — `increase(support_requests_total{category="purchase_affected"}[10m]) > 0`, `for: 0m`, severity **warning**, summary "A visitor reports a purchase problem", runbook `docs/operations.md#konventixsupportpurchaseaffected` (open the console inbox, find the request by the reference in the annotation, paste the correlation ids into the logs dashboard). This is #96's "Meldungen der Kategorie Kauf betroffen laufen auf denselben Kanal".
- `KonventixSupportUnseen` — `support_requests_unseen_oldest_age_seconds > 3600`, severity warning: a report nobody has opened for an hour.

Both with vmalert-tool unit tests. Log: `SupportRequestOpened` writes one `support.request` info record with the request id, categories and both correlation ids, so the report is itself findable by the id it quotes.

### 10.7 Retention

`support:purge-requests` (daily, `withoutOverlapping()`): deletes requests with `closed_at < now() - 180 days` and their messages; logs the count. `config/support.php` `retention.days = 180`. Compliance's retention rules UI (`ManageRetentionRules`) is not wired in this design; flagged (§14).

### 10.8 Audit

The four events implement `AuditableEvent` so Compliance's wildcard subscriber records them. Viewing a reporter's unmasked contact calls `AuditRecorder::record('support.reporter-contact.viewed', …)` explicitly.

## 11. Privacy

Stored: what the person typed, the categories, their account id or address, the technical context of §5. Not stored: IP, cookies, form contents of the reporting page, screenshots. The dialog discloses the context before sending. Retention 180 days after closing; GDPR export and erasure through the module handler; contact data behind its own capability; reply mails only with consent. `.ai/rules/observability.md` still applies: log records carry ids, never the message text or the address.

## 12. UI and accessibility

- Placement: bottom-right, `right: 1rem; bottom: 1rem` (brand), inside the content inset on the console; on viewports under 640 px a 44 px circle, above 640 px a pill with the label. Never overlaps the toaster (toasts render above it) or the cookie banner (launcher hidden while it is open).
- WCAG 2.1 AA (ADR-0055): visible focus ring, semantic tokens only (ADR-0008), `aria-describedby` on the textarea for the counter and the error, chips announce pressed state, dialog title is the accessible name, Escape closes and restores focus.
- Reduced motion: no slide-in.
- Copy: English strings in the components; the reference is shown in the toast and in the receipt mail in the same form.

## 13. Testing

Pest, in `app-modules/support/tests`:

- `SupportRequestSubmissionTest`: account and guest happy paths (201, row, context server fields overwrite client fields, page id validated), validation (422 shapes), honeypot (201, nothing stored), throttles (429 on the 6th within 10 minutes; per-email 4th within an hour), console-host submission (`filed_on_brand_id` null, `brand` = `console` label).
- `SupportNotificationsTest`: receipt to guest with a signed URL that verifies; receipt to account; operator notice to triage holders with `urgent`; no state mail without consent; `Event::fake([...])` with explicit classes.
- `GuestAccessTest`: signed show 200, reply 201, expired signature → link-expired page, re-issue mails a valid link and is throttled, purged request 404.
- `MyRequestsTest`: index and show scoped to the actor, reply, policy denies others.
- `ConsoleTest`: index filters, first open sets `seen`, transitions allowed/denied per state table, known-issue toggling feeds `/support/known-issues`, contact masked without `view-reporter-contact`, audit rows written.
- `SupportGdprHandlerTest`: export by user and by e-mail, delete idempotent, rows kept.
- `SupportMetricsTest` + `ObservabilityCoverageTest` green; alert unit tests via vmalert-tool.
- `SupportRetentionTest`: purge respects the window.
- Architecture test: 19 providers unchanged; `Support` imports no foreign model.
- Vue: `SupportLauncher` renders in both layouts (vitest), dialog validates at least one category client-side, `useSupportContext` ring buffer keeps five.

## 14. Decisions and flagged items

Operator decisions (2026-09-21, chat), recorded here and in `docs/inventory/decision-log.md` as D-SUP-01…:

| Id | Decision |
|---|---|
| D-SUP-01 | Ship #88 completely, not a cutover subset; slices order the work. |
| D-SUP-02 | The launcher is on every brand host and on the operator console; Support becomes non-gateable (ADR-0063). |
| D-SUP-03 | Data model §4 with reserved `external_reference` and `subject_module`/`subject_id`. |
| D-SUP-04 | Trust boundary §5: server-derived fields win; two correlation ids; visit ring buffer. |
| D-SUP-05 | Guests may file with an e-mail address; throttle per user/IP plus per address, honeypot, no stored IP; a captcha seam (`CaptchaVerifier`) exists from day one, no captcha configured. |
| D-SUP-06 | "Purchase affected" reaches the alert channel through a metric and a vmalert rule, and operators through Support's own topic — never Commerce's topic key. |
| D-SUP-07 | Capabilities view / triage / view-reporter-contact; default role Support Desk; reporters get "My support requests"; guests get the whole process by mail with 30-day signed links and self-service re-issue. |
| D-SUP-08 | No screenshots, no form contents, no IP; 180-day retention after closing; GDPR handler exports by user id and by address. |
| D-SUP-09 | Dialog composition §6.2; English UI copy like the rest of the app; `de`/`en` mail templates. |
| D-SUP-10 | Operators are notified in-app and by mail on every new request; the monitoring alert is out of band and additional. |

Flagged for the operator (no blocker):

- `context.commit` stays null until `APP_COMMIT` is passed by the image build and chart (a chart follow-up; the Helm memory says the chart pins the app tag, so the sha is available at build time).
- Which triage holders a **console-filed** request notifies: first slice notifies every user holding `support.triage-requests` on any brand of the operator; refine when a second operator exists.
- Sidebar badge with the open count depends on `NavigationRegistry` supporting badges; not in this design.
- Compliance's retention-rules UI is not wired to `support:purge-requests`; the window is config.
- i18n of UI strings is a platform-wide question; the dialog does not start it.
- The `subject_module`/`subject_id` reference and ADR-0022's "ask about this" slot are §16.

## 15. Slices and issues

| Slice | Content | Issue |
|---|---|---|
| S0 Foundation | ADR-0063, SPEC.md, migrations, enums, models, Data objects, events, Permission + default role, GDPR handler, metrics catalogue + panel + alerts, retention command, `config/support.php`, tests for model/GDPR/retention | #304 |
| S1 Report channel | Launcher + dialog + textarea primitive + `useSupportContext`, `POST` routes on both hosts, limiter, honeypot, `CaptchaVerifier`, `OpenSupportRequest`, receipt mails (guest link, account), operator notice topic + template, `support.request` log record, alert rule test, submission and notification tests | #305 |
| S2 Console | Inbox, detail with logs deep link, transitions, assignment, known issue + `/support/known-issues` + dialog banner, external reference, messages (internal/external), audit, navigation, console tests | #306 |
| S3 Reporter access | `/my/support-requests` (index, show, reply, account nav), guest signed show/reply, link-expired page and re-issue, state-changed and replied mails, guest/my tests, a11y pass on launcher and dialog, runbook section | #307 |

S0 and S1 are `unverzichtbar`; S2 and S3 complete #88. Milestone for all four: Phase 4 - Hypercare (Phase 3 has expired; the operator may move S0/S1 into Cutover).

## 16. Out of scope (tracked under #48 and #97)

- GitLab integration (`external_reference` is a free text field).
- Typed "ask about this" references from other modules through a Platform UI slot (ADR-0022) — `subject_module`/`subject_id` are reserved.
- Attachments and screenshots (would go through Platform's media library, ADR-0035, which today has no brand-host upload route).
- Knowledge base (Content type, ADR-0023), SLAs, macros, satisfaction surveys — the scope ceiling in `SPEC.md`.
- Support address per brand and Reply-To (#97).
- A captcha driver (seam exists).
- Real tracing (#122).
