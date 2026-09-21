# Support — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`. Design: `docs/superpowers/specs/2026-09-21-support-requests-design.md`.

## Purpose

Support owns the way a person tells the operator that something is wrong or
could be better, and the operator's handling of that report until it is
closed. A `SupportRequest` is filed from any page through the floating
launcher, carries the technical context the reporter cannot be expected to
know (both correlation ids, route, release, brand, account, previous page,
browser), and is worked in the console: seen, in progress, resolved or won't
fix, with internal notes and external replies. The reporter follows it signed
in under "My support requests" or, as a guest, through a mailed signed link.
At go-live this is the operator's primary early-warning system (#88): people
notice what no test covers, and the report already quotes the id that leads
to the log lines behind it (ADR-0056).

## Scope ceiling

ADR-0022 asks for this to be written before the first line of code. Support
is a report channel with a thread, not a helpdesk product. It does **not**
grow SLAs, macros, canned replies, escalation rules, satisfaction surveys,
a knowledge base (that is a Content type, ADR-0023), live chat, or a GitLab
integration (the `external_reference` column is a free text field and stays
one). A request for any of these is a request for an external tool the
operator deep-links; the module's job is to keep the record GDPR-exportable
and the context attached.

## Owns

- **`SupportRequest`**: reporter (account ULID or e-mail address), categories
  (`purchase_affected`, `visibility`, `function`, `display`, `suggestion`),
  message, state (`received`, `seen`, `in_progress`, `resolved`, `wont_fix`),
  known-issue flag and notice, assignment, external reference, the typed
  `context` snapshot (`SupportContextData`), and the reserved typed subject
  reference (`subject_module`, `subject_id`) for ADR-0022's "ask about this".
  `filed_on_brand_id` is provenance, never a scope (ADR-0050): the console
  lists across brands and a request filed on the operator host has none.
  The model therefore carries **no** `BelongsToBrand` — the one model in
  the codebase that deliberately does not — and scoping is explicit
  (`scopeFiledOnBrand()`). A global `BrandScope` would have hidden the
  console-filed rows entirely and, on the admin host where the inbox is
  read, hardened every query to `WHERE 1=0`.
- **`SupportRequestMessage`**: the thread after the original message, each
  entry `internal` (staff note) or `external` (reporter sees it, may write
  one).
- **Known issues**: the active known-issue notices a brand's dialog shows.
- **Capture rules**: what the client may send and what the server derives
  and overwrites (design §5), the honeypot, the rate limits, the
  `CaptchaVerifier` seam.
- **Reporter access**: "My support requests" for accounts; 30-day signed links
  with self-service re-issue for guests.
- **Retention**: closed requests are purged after `support.retention.days`.

## Does not own

- The correlation id and the log context — host (`App\Observability`,
  ADR-0056, ADR-0059). Support reads `CorrelationId::current()` and quotes
  the shared `correlationId` prop; it never mints ids.
- Delivery of any mail or in-app notice — Messaging (ADR-0041). Support
  declares its topics and templates and calls `SendMessage`.
- Alerting — the platform's vmalert/Alertmanager on Support's metrics
  (ADR-0059). "Purchase affected" reaches the on-call channel as an alert
  rule, not as a Messaging send.
- Who may triage — roles are per-brand data (ADR-0013); Support declares the
  capabilities and a default role only.
- Admission credentials — the word "ticket" means an admission document in
  this codebase (ADR-0005, ADR-0061) and never a support request.
- Files — attachments, if they ever come, are Platform media (ADR-0035).
- The audit trail — Compliance records Support's auditable events.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| `SupportRequestOpened` | a report is stored | `requestId`, `brandId?`, `userId?`, `categories`, `release` |
| `SupportRequestStateChanged` | staff move the state, or the first console open sets `seen` | `requestId`, `brandId?`, `from`, `to`, `actorUserId?` |
| `SupportRequestMessagePosted` | a note or reply is added | `requestId`, `brandId?`, `messageId`, `visibility`, `authorUserId?` |
| `SupportRequestKnownIssueMarked` | the known-issue flag changes | `requestId`, `brandId?`, `isKnownIssue`, `actorUserId` |

## Consumes

| Event | Owning module | Why |
|---|---|---|
| — | — | Support maintains no read model from other modules in this design. The typed subject reference is filled by the caller of the future UI slot, not by a listener. |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- `support.view-requests` — see the inbox and a request.
- `support.triage-requests` — change state, assign, mark known issues, set
  the external reference, post notes and replies.
- `support.view-reporter-contact` — see an unmasked address or account;
  every unmasked view is audited.

Default role (ADR-0045): `support.desk` "Support Desk" with all three.
Filing a report needs no capability: `OpenSupportRequest` is the module's
one public write, open to guests, guarded by throttles and the honeypot.

## The report channel

One endpoint, two routes, one Action. `POST /support/requests` on every
brand host and `POST /admin/support/requests` on the operator console both
reach `SupportRequestController::store` and `OpenSupportRequest`; which host
a report came in on is the `filed_on_brand_id` column and never a second
code path. The answer is JSON — the dialog posts through Inertia's `useHttp`
and the page underneath is never replaced, because the person reporting a
broken checkout is standing on it.

Filing needs no capability and no account. What stands in place of
authorization, in the order it runs:

1. `throttle:support-report` — five reports per ten minutes per account, or
   per address of origin for a guest.
2. The honeypot field, checked before validation: a filled one answers 201
   with a plausible reference and stores nothing, so a bot learns nothing
   from the difference.
3. Three reports an hour per e-mail address (`sha1`ed into a limiter key) —
   the axis a pool of source addresses cannot spend around.
4. `Captcha\CaptchaVerifier`, bound to `NullCaptchaVerifier`. There is no
   captcha and `config('support.captcha.driver')` is null; the seam exists
   so turning one on is configuration and one class, never a redesign of the
   endpoint.

No IP address is stored anywhere — the limiters hash it and forget it.

`Context\SupportContextFactory` is where the trust boundary of the design's
§5 is applied: the browser is believed about what only the browser knows
(the URL, the visit buffer, the viewport, the last client error, the page's
own correlation id, each length- or shape-bounded), and every other field —
route, release, brand, event, account, user agent, arrival time, the submit
correlation id — is derived by the server and overwrites whatever arrived.

`Links\SupportLinks` builds every link a Support mail carries, always
absolute: the brand's own origin for a reporter link, the operator's console
origin (`OperatorSettings::adminBaseUrlForBrand()`) for an operator one.

## The console

`GET /admin/support-requests` is the inbox and `GET /admin/support-requests/{request}`
one report, both on the operator host behind `support.view-requests`.

The inbox lists **across brands, plus the brandless rows**, and needs no
`withoutBrandScope()` anywhere — the model carries no `BelongsToBrand`, which
is the whole reason that choice was made (see "Owns"). It filters on state
(defaulting to the open pile; clearing sends the literal `all`), category,
brand (with a `Console` option for a report that has none) and the
known-issue mark, and searches the reference, the message and the external
reference. Not the reporter's address: finding people by address is not
triage, and `support.view-reporter-contact` exists to make every such
look-up a recorded one.

Opening a report as the first staff member moves it `received → seen`
through `TransitionSupportRequest`, so the move is audited, counted, and
ends the clock `support_requests_unseen_oldest_age_seconds` runs.

The detail page shows the whole `context` snapshot — both correlation ids
with copy buttons, route, release, brand, previous page, the five visits,
the viewport and the last script error — and a **"Find in logs"** link per
id, built by `Links\SupportLinks::logsUrlFor()` from
`config('support.logs.correlation_url')` by substituting `:correlationId`.
Unset, or a template without the placeholder, renders no button: a link that
opens the whole log stream while looking filtered is worse than none.

Five writes, each its own route, Action and audit entry, all behind
`support.triage-requests`:

| Route | Action | Audit |
|---|---|---|
| `PATCH …/state` | `TransitionSupportRequest` | `support.request.state-changed` (event) |
| `PATCH …/assignee` | `AssignSupportRequest` | `support.request.assigned` (explicit) |
| `PATCH …/known-issue` | `MarkKnownIssue` | `support.request.known-issue-marked` (event) |
| `PATCH …/external-reference` | `SetExternalReference` | `support.request.external-reference-set` (explicit) |
| `POST …/messages` | `PostSupportMessage` | `support.request.message-posted` (event) |

Three ride an `AuditableEvent` that Compliance's wildcard subscriber
records; assignment and the external reference have no event, because
nothing outside this module could act on either and §10.4 fixes the public
contract at four events — so the controller calls `AuditRecorder` after the
Action, where an audit write cannot take part in a rollback. Reading a
reporter's unmasked contact is recorded the same way, as
`support.reporter-contact.viewed`, every single time.

The assignee picker offers the `support.triage-requests` holders of the
report's brand, or the `platform.access-admin` holders of the operator for a
console-filed one (`Queries\TriageHolders`, the same rule the notice of §7
applies), and the endpoint checks that list again rather than trusting the
id it was sent. Accounts appear as ULIDs: the display name lives in Identity
and this module may not read it (ADR-0004), the same reason Platform's own
role editor prints them.

Navigation is one entry, `Support requests`, with the **open count as a
badge** (D-SUP-13). The count is brand-wide — every open report of every
brand plus the console-filed ones, which is what the inbox defaults to
showing — and it is registered as a closure, resolved when Platform
serialises the entry for one request. Never at boot: a number captured at
registration would be frozen for an Octane worker's whole life.

## Known issues

`GET /support/known-issues` (brand host, no authentication, cached 60
seconds per brand in `Queries\KnownIssues`) lists the reports triage has
marked, whose state is not terminal, filed on this brand **or on none** — a
brandless mark is the operator-wide notice that reaches every brand host at
once.

The answer is `{id, notice}` and nothing else. `notice` is the sentence an
operator wrote, or the category label when they have not written one yet;
it is never the reporter's message. Resolving the canonical report takes the
banner down by itself, and `MarkKnownIssue` forgets the cache key so a wrong
notice comes down at once rather than within the minute.

`SupportLauncher` fetches this when the dialog opens — never on page load,
and never on the console, which resolves no brand whose visitors need
warning. A banner per notice, above everything else in the dialog; a report
is still welcome afterwards.

## Notifications

Two topics, declared in the provider beside the templates that use them.

| Topic | Audience | Channels | Digestable |
|---|---|---|---|
| `support.requests` | operator | in-app, mail | yes (today's sends are `urgent`) |
| `support.request-updates` | participant (the reporter) | in-app, mail | no |

`support.requests` is an operator-audience topic, so its in-app rows show in
the console bell and never in the participant inbox of an organizer who is
also a registrant.

| Template | To | Notes |
|---|---|---|
| `support.request-received` | the reporter | Sent regardless of `contact_consent`: it is the receipt, and for a guest the signed 30-day link in it is the only key to the request. An account's receipt rides `support.request-updates`; a guest's rides no topic, because there is no account to hold a preference. |
| `support.request-notice` | operators | `urgent`, with an absolute console link. Recipients are the `support.triage-requests` holders on the brand — or, for a console-filed report that has no brand, the `platform.access-admin` holders of the operator (D-SUP-12). The same template carries a **reporter's reply**: one operator notice, three fields, nothing for an override editor to learn twice. |
| `support.request-state-changed` | the reporter | On a move to `in_progress`, `resolved` or `wont_fix` — never on `seen`, which the first console open sets seconds after the receipt. **Only with `contact_consent`.** |
| `support.request-replied` | the reporter | On an **external** message written by somebody other than the reporter. Quotes the first 200 characters and links to the thread. **Only with `contact_consent`.** |
| `support.request-link` | a guest reporter | A fresh 30-day signed link, on self-service re-issue. Sent to the stored address only. |

Both templates are branch-free: every sentence that differs between a guest
and an account is a prepared payload field, because a per-brand override may
contain no expression (`TemplateOverrideGuard`) and the editor seeds itself
with the shipped default.

A console-filed report still needs *a* brand for Messaging, whose delivery
log, suppression list and quota are brand-scoped and whose `brand_id` is not
nullable; the operator's oldest brand stands in. The row itself stays
brandless everywhere it is read.

`contact_consent` gates everything **after** the receipt, and the gate lives
in one place — `Listeners\Concerns\ReachesTheReporter` — so that a fourth
update mail cannot forget it. The receipt is the single exception and says
so in its own listener: for a guest it is the only key to the report, and
withholding it would mean accepting a report the person can never read
again.

A thread has two directions and one event. `SupportRequestMessagePosted`
feeds two listeners that split on who wrote the entry: an operator's
external reply is news to the reporter, a reporter's own reply is news to
the triage holders (through `Queries\TriageHolders`, the same rule the
opened notice applies), and neither is news to the person who just typed it.
An internal note reaches nobody outside the console.

Every send is best-effort: a notification that cannot be delivered is a
`Log::warning`, never a failed report.

## Reporter access

Two ways in, one page. `support/requests/Show` is rendered for both, and the
only prop that differs is `replyUrl` — relative for an account, signed for a
guest. They are one component on purpose: the moment they diverge is the
moment one of them stops hiding internal notes.

**Accounts.** `GET /my/support-requests` lists everything this account has
reported and `GET /my/support-requests/{request}` is one of them, with a
reply form while the report is not terminal (`POST …/reply`). Authorization
is ownership, checked in the query, and everything else is a **404** rather
than a 403 — a 403 on an eight-character reference is an answer about
whether it exists. There is no capability and none is missing, the same
reasoning `/my/orders` and `/my/tickets` carry. The list is deliberately
**not** brand-filtered, unlike every other `/my/*` list: a report is about
the platform, filed from wherever the person happened to be standing, and a
list that hid it because they opened the account menu on another host would
be a list that loses reports. Navigation is one `AREA_ACCOUNT` entry, "My
support requests", with no badge — a person's own reports are not a work
queue.

**Guests.** The receipt carries `URL::temporarySignedRoute` for 30 days
(`SupportLinks::GUEST_LINK_DAYS`); `GET /support/requests/{request}` and
`POST …/reply` are both `signed`. The signature is the whole authorization
and no session is written. The reply URL and the redirect after a reply
carry **the expiry the visit arrived with**, never a fresh 30 days, so a
page left open does not quietly become a page open forever.

An expired or tampered signature is **not** a 403. `support-link-expired`
sits in front of `signed` on those routes, asks the same
`hasValidSignature()` question, and renders `support/requests/LinkExpired`
when the answer is no — a form with one field and one button. (Catching
`InvalidSignatureException` in an outer middleware was the obvious shape and
does not work: `Illuminate\Routing\Pipeline` wraps every pipe in its own
try/catch and hands the throwable to the exception handler, so an outer
frame only ever sees a finished 403.) `signed` stays behind it: if the two
checks ever drift, the route refuses rather than opening.

`POST /support/requests/link` is the re-issue, and its design is what it
refuses to say. It takes a **reference and nothing else** — a form with an
address field would let anybody holding a reference move somebody else's
report to an inbox of their choosing — and it answers the same 202 for a
reference that exists, one that never did, one the retention sweep purged
and one an account filed. The route carries `throttle:support-link`, three
an hour keyed on the normalised reference, because the thing being protected
is one known inbox rather than one caller. The design's §8.2 sketched a 404
for a purged report; that is deliberately not built, because one answer for
every input is the stronger property.

## Accessibility

The a11y pass of design §12 (ADR-0055, WCAG 2.1 AA) covers the launcher, the
dialog and the four reporter surfaces. What was checked, and what holds:

- **Headings.** Every page has exactly one `h1` (`Heading as="h1"`), and no
  level is skipped: the report page's thread and its technical details are
  `<section>`s under their own `h2`, the second one visually hidden because
  the reused context card carries its own visible `h3`. The dialog's
  accessible name is its `DialogTitle`.
- **Labels and errors.** Every control has a real `<label>` (or an
  `aria-label` where only an icon is visible, as on the launcher). Each
  error is linked to its field through `aria-describedby` plus
  `aria-invalid`, never rendered loose beside it; the re-issue field also
  carries its format hint that way.
- **Focus.** After a reply — succeeded or refused — focus moves to the
  `role="status"` region that says what happened, because the box is empty
  again and the page otherwise looks unchanged. The reply visit is made with
  `preserveState` so that region survives it. The dialog moves focus to the
  first invalid field on a failed submit, and reka-ui traps and restores
  focus around it.
- **Live regions.** `role="status"` on the reply result and on the re-issue
  answer; `role="alert"` on the dialog's transport failure. Nothing ticking
  is announced.
- **Keyboard.** Every row of "My support requests" carries a real `<Link>`,
  so nothing is reachable by pointer only. The launcher is a `<button>` with
  `aria-haspopup="dialog"`, `aria-expanded` and a 44 px target.
- **Tokens.** Semantic only (ADR-0008), asserted per component.

Static promises — an attribute that must exist, a heading level, the absence
of the word "internal" in the reporter's thread — are pinned in
`resources/js/components/supportMarkup.test.ts`. Focus order and the focus
trap are reka-ui's and are not pretended at there.

## Activation

Kernel-style, always active and not gateable (ADR-0063, amending
ADR-0022): the provider sets `$gateable = false` and the routes carry no
`ensure-module`. A report channel is a property of the platform, not a
feature a brand opts into — and the operator console, which has no brand
in context, must carry the launcher too, which a Pennant gate cannot allow
(`ModuleGate::isActive()` reads "no brand" as inactive). If a later helpdesk
half needs a per-brand opt-in, it is a per-brand feature beside the module
(ADR-0047's shape), not a module gate.

## Observability

ADR-0059. Catalogue in `src/Metrics/SupportMetrics.php`, counters written
by `src/Listeners/Metrics/RecordSupportMetrics.php` from this module's own
events, gauges answered by `src/Metrics/SupportGauges.php` at scrape time,
panels in the platform dashboard's **Support** row.

| Metric | Kind | Labels |
|---|---|---|
| `support_requests_total` | counter | `brand`, `category` — one increment per *selected* category |
| `support_request_transitions_total` | counter | `brand`, `state` (the state moved into) |
| `support_requests_open` | gauge | `brand` — received + seen + in progress, every brand and `console`, zero included |
| `support_requests_unseen_oldest_age_seconds` | gauge | none — 0 when nothing is waiting |

`brand` is a slug, or the literal `console` for a report filed on the
operator host, which has no brand (ADR-0063). Nothing unbounded is ever a
label: the request id and the reference belong in the `support.request` log
record, not in a series.

Alerts: `KonventixSupportPurchaseAffected` (a report in that category in
the last ten minutes — D-SUP-06's "purchase affected reaches the alert
channel", never Commerce's topic key) and `KonventixSupportUnseen` (the
oldest unopened report is over an hour old). Both have runbook sections in
`docs/operations.md` and vmalert-tool unit tests.

## Retention

`support:purge-requests`, daily and `withoutOverlapping()`, deletes closed
requests and their threads once `support.retention.days`
(`config/support.php`, 180) has passed since `closed_at`. Only a terminal
state sets `closed_at`, so an **open** report is never purged however old
it is — that is a triage problem, and the unseen-age gauge is what reports
it. A window below one day is refused by the sweep rather than obeyed.

## Open questions

- Whether Compliance's retention rules (`compliance.manage-retention-rules`)
  should drive `support:purge-requests` instead of `config/support.php`.
- Whether a guest whose address later gets an account should be migrated
  onto it. Today they are not: the GDPR handler answers by user id **and**
  by address, so the person still gets their data, but their old report
  does not appear under "My support requests" (design §8.2).
- Whether Platform should maintain an identity read model so the console can
  name an assignee and a note's author instead of printing ULIDs. The same
  question Platform's role editor left open, and the same answer for now.
- Whether the shared `auth.user` prop should carry the account's address so
  the dialog can show it read-only (design §6.2 item 5). It does not today
  (ADR-0051 §5 keeps that prop lean), so the dialog names the account
  instead of the address.
- Which triage holders a console-filed request (no brand) notifies once the
  instance has more than one operator.
- `context.commit`: null until the image build passes a sha (`APP_COMMIT`).
- UI copy language: English like the rest of the application until the
  platform decides on i18n.
