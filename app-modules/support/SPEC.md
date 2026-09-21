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
| `support.request-notice` | operators | `urgent`, with an absolute console link. Recipients are the `support.triage-requests` holders on the brand — or, for a console-filed report that has no brand, the `platform.access-admin` holders of the operator (D-SUP-12). |

Both templates are branch-free: every sentence that differs between a guest
and an account is a prepared payload field, because a per-brand override may
contain no expression (`TemplateOverrideGuard`) and the editor seeds itself
with the shipped default.

A console-filed report still needs *a* brand for Messaging, whose delivery
log, suppression list and quota are brand-scoped and whose `brand_id` is not
nullable; the operator's oldest brand stands in. The row itself stays
brandless everywhere it is read.

Every send is best-effort: a notification that cannot be delivered is a
`Log::warning`, never a failed report.

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
- Whether the shared `auth.user` prop should carry the account's address so
  the dialog can show it read-only (design §6.2 item 5). It does not today
  (ADR-0051 §5 keeps that prop lean), so the dialog names the account
  instead of the address.
- Which triage holders a console-filed request (no brand) notifies once the
  instance has more than one operator.
- `context.commit`: null until the image build passes a sha (`APP_COMMIT`).
- UI copy language: English like the rest of the application until the
  platform decides on i18n.
