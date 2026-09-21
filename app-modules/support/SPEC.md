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

## Activation

Kernel-style, always active and not gateable (ADR-0063, amending
ADR-0022): the provider sets `$gateable = false` and the routes carry no
`ensure-module`. A report channel is a property of the platform, not a
feature a brand opts into — and the operator console, which has no brand
in context, must carry the launcher too, which a Pennant gate cannot allow
(`ModuleGate::isActive()` reads "no brand" as inactive). If a later helpdesk
half needs a per-brand opt-in, it is a per-brand feature beside the module
(ADR-0047's shape), not a module gate.

## Open questions

- Whether Compliance's retention rules (`compliance.manage-retention-rules`)
  should drive `support:purge-requests` instead of `config/support.php`.
- Which triage holders a console-filed request (no brand) notifies once the
  instance has more than one operator.
- `context.commit`: null until the image build passes a sha (`APP_COMMIT`).
- UI copy language: English like the rest of the application until the
  platform decides on i18n.
