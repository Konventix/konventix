# Support — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] S2 Console (#306, #88): inbox, detail with logs deep link, transitions,
      assignment, known issues + `/support/known-issues` + dialog banner,
      external reference, messages, audit, navigation.
- [ ] S3 Reporter access (#307, #88): `/my/support-requests`, guest signed links,
      link re-issue, state-changed and replied mails, a11y pass, runbook.

## Backlog

- [ ] Wire `docs/architecture/module-map.md` (row "Support") and ROADMAP's
      Support entries from "post-release" to "report channel at go-live
      (#88), helpdesk half after the event (#48)" — ADR-0063's last
      consequence, left for the slice that touches those documents.
- [ ] Typed "ask about this" reference through a Platform UI slot
      (ADR-0022); `subject_module`/`subject_id` are reserved for it.
- [ ] Attachments through Platform media (needs a brand-host upload route).
- [ ] A captcha driver behind `CaptchaVerifier` (Turnstile), if abuse shows up.
- [ ] Drop the `Route::has()` fallbacks in `Links\SupportLinks` once S2
      registers `admin.support.requests.show` and S3 registers
      `support.requests.show` / `support.requests.mine.show` — the literal
      paths beside them are the TODO markers.
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
