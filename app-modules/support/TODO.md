# Support — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Nothing owed for #88. The four slices are on `develop`; what is left
      below is the helpdesk half (#48) and the follow-ups each slice named.

## Backlog

- [ ] Typed "ask about this" reference through a Platform UI slot
      (ADR-0022); `subject_module`/`subject_id` are reserved for it.
- [ ] Migrate a guest's report onto an account when that address later gets
      one. It is not done today (design §8.2): the GDPR handler answers by
      user id *and* by address, so the person still gets their data, but the
      old report never appears under "My support requests".
- [ ] Decide whether a reporter's reply should reopen a report that triage
      has moved to `in_progress` and back — today a terminal report simply
      refuses the reply and asks for a new one, which is the honest
      behaviour but loses the connection between the two.
- [ ] Attachments through Platform media (needs a brand-host upload route).
- [ ] A captcha driver behind `CaptchaVerifier` (Turnstile), if abuse shows up.
- [ ] Name an assignee and a note's author instead of printing their ULID,
      once Platform maintains an identity read model (the same gap
      `RoleAssignmentController` documents).
- [ ] Decide whether `auth.user` should carry the account's address, so the
      dialog can show it read-only instead of naming the account
      (design §6.2 item 5; ADR-0051 §5 keeps that prop lean today).
- [ ] A console-filed report's operator notice is booked on the operator's
      oldest brand, because `messaging_messages.brand_id` is not nullable.
      Revisit if Messaging ever grows an operator-level scope.
- [ ] `context.commit` once the image build passes `APP_COMMIT`.
- [ ] Support address per brand and Reply-To (#97).
- [ ] Knowledge base as a Content type (#48).

## Done

- [x] S3 Reporter access (#307, #88): `GET /my/support-requests` and
      `/my/support-requests/{request}` behind `auth`, authorized on
      ownership with a 404 for everything else and deliberately not
      brand-filtered, plus `POST …/reply` through the new `PostReporterReply`
      (external, terminal reports refused on the `body` field); the
      `AREA_ACCOUNT` navigation entry with no badge; the guest's signed
      `GET /support/requests/{request}` and `POST …/reply`, whose reply URL
      and post-reply redirect carry the expiry the visit arrived with rather
      than a fresh 30 days; the `support-link-expired` middleware rendering
      `support/requests/LinkExpired` instead of a 403 (checking
      `hasValidSignature()` itself — `Illuminate\Routing\Pipeline` catches
      per pipe, so an outer frame never sees `InvalidSignatureException`);
      `POST /support/requests/link` with `ReissueSupportLink`, a reference
      and nothing else, the stored address only, one answer for every input
      and `throttle:support-link` at three an hour per reference;
      `support.request-state-changed`, `support.request-replied` and
      `support.request-link` in `de`/`en`, branch-free, with the consent
      gate in `Listeners\Concerns\ReachesTheReporter` so a fourth update
      mail cannot forget it; `NotifyReporterOnStateChanged`,
      `NotifyReporterOnReply` and `NotifyOperatorsOnReporterReply` splitting
      one event by who wrote the entry; `SupportLinks` free of its
      `Route::has()` fallbacks; the a11y pass written up in SPEC.md and
      pinned in `supportMarkup.test.ts`; the runbook's "Reporter access"
      subsection; the module map's Support row and ROADMAP's Support entries
      corrected off "post-release".
- [x] S2 Console (#306, #88): `GET /admin/support-requests` with
      `useDataTable` (reference, categories, state, brand or `Console`,
      release, masked reporter, received, assignee, known-issue mark) and
      filters for state — defaulting to the open pile — category, brand and
      the known-issue mark; the detail page with the whole context snapshot,
      copy buttons and `Links\SupportLinks::logsUrlFor()` behind
      `config('support.logs.correlation_url')`; `TransitionSupportRequest`
      offered as exactly `allowedNext()`, `AssignSupportRequest` over
      `Queries\TriageHolders`, `MarkKnownIssue`, `SetExternalReference` and
      `PostSupportMessage` with an explicit visibility; `seen_at` stamped on
      the first console open; an audit entry on every write and on every
      unmasked contact view; `GET /support/known-issues` (no auth, cached 60
      s, id and sentence only) wired into the dialog's banner through
      `useKnownIssues`; the console navigation entry with the brand-wide
      open count, for which Platform's `NavigationItem` gained an optional
      `badge` closure resolved at serialisation time (D-SUP-13).
- [x] S1 Report channel (#305, #88): `components/ui/textarea`;
      `SupportLauncher` mounted from `AppTopbarLayout` and `AdminLayout`
      through `resolveRegisteredComponent`; `SupportRequestDialog` in the
      design's §6.2 order with the known-issue banner placeholder, category
      chips, the 10–4000 counter, the guest address field, the consent
      checkbox, the "What we send" disclosure and the honeypot, submitting
      through `useHttp`; `useSupportContext` with the five-visit ring buffer
      and the two correlation ids; `POST /support/requests` on both hosts
      with `throttle:support-report`, the per-address budget, the honeypot's
      fake 201 and the `CaptchaVerifier` seam; `OpenSupportRequest` and the
      `support.request` log record; `SupportContextFactory` applying the
      trust boundary; `SupportLinks`; the `support.requests` and
      `support.request-updates` topics with the `support.request-received`
      and `support.request-notice` templates in `de`/`en`; submission,
      trust-boundary, abuse, notification and a11y tests.
- [x] Write `SPEC.md` — purpose, scope ceiling, ownership boundary, event
      contract (design 2026-09-21, this branch).
- [x] S0 Foundation (#304, #88): `$gateable = false` (ADR-0063), migrations
      `support_requests` / `support_request_messages` with the reserved
      `external_reference` and typed subject reference, enums
      (`SupportCategory`, `SupportRequestState`, `MessageVisibility`,
      `Permission`), models without `BelongsToBrand`, factories, Data
      objects (`SupportContextData` as the Eloquent cast,
      `SupportClientContextData`, `SupportRequestInputData`, the two read
      models), the four `AuditableEvent`s, `TransitionSupportRequest`,
      `support.desk`, `SupportGdprHandler`, `SupportMetrics` +
      `SupportGauges` + the platform dashboard's Support row + both alert
      rules with vmalert unit tests and runbook sections,
      `support:purge-requests` daily with `withoutOverlapping()`, and
      `config/support.php` (retention, the captcha seam, the logs deep
      link).
