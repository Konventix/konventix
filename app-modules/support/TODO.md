# Support — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] S0 Foundation (#304, #88): ADR-0063, migrations, enums, models, Data objects,
      events, `Permission` + `support.desk`, `SupportGdprHandler`,
      `SupportMetrics` + panel + alert rules, `support:purge-requests`,
      `config/support.php`.
- [ ] S1 Report channel (#305, #88): `SupportLauncher`, `SupportRequestDialog`,
      `components/ui/textarea`, `useSupportContext`, `POST /support/requests`
      on both hosts, `throttle:support-report`, honeypot, `CaptchaVerifier`,
      `OpenSupportRequest`, receipt mails, `support.requests` topic and
      operator notice.
- [ ] S2 Console (#306, #88): inbox, detail with logs deep link, transitions,
      assignment, known issues + `/support/known-issues` + dialog banner,
      external reference, messages, audit, navigation.
- [ ] S3 Reporter access (#307, #88): `/my/support-requests`, guest signed links,
      link re-issue, state-changed and replied mails, a11y pass, runbook.

## Backlog

- [ ] Typed "ask about this" reference through a Platform UI slot
      (ADR-0022); `subject_module`/`subject_id` are reserved for it.
- [ ] Attachments through Platform media (needs a brand-host upload route).
- [ ] A captcha driver behind `CaptchaVerifier` (Turnstile), if abuse shows up.
- [ ] `context.commit` once the image build passes `APP_COMMIT`.
- [ ] Support address per brand and Reply-To (#97).
- [ ] Knowledge base as a Content type (#48).

## Done

- [x] Write `SPEC.md` — purpose, scope ceiling, ownership boundary, event
      contract (design 2026-09-21, this branch).
