# Messaging — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Messaging owns the way the platform talks to people: a channel abstraction over
mail, push and in-app notification, the templates rendered per brand, and the
delivery log kept as infrastructure. It absorbs LanCore's Notification and
EmailLog domains. Messaging is deliberately a kernel *service*, not a domain
holding opinions: a module hands it a recipient ULID, a template key and a typed
payload, and gets delivery semantics in return. Nothing about *why* a message is
sent lives here — the module that owns the process decides that. One opinion it
does hold, settled in the decision log Round 6 gate review (D-MSG-03, user
override): **coalescing is Messaging's job.** Modules still send per-change,
exactly as before, but Messaging owns digest windows and quiet hours as
infrastructure and may hold and merge sends rather than dispatching each one
immediately — "thin service" describes what triggers a send, not what happens
between acceptance and delivery.

Using Messaging is not a cross-module coupling in the ADR-0004 sense. No Eloquent
model and no domain state crosses the boundary; the interface is a contract over
scalars and ULIDs, which is exactly what the rule asks for.

## Owns

- **Channel abstraction**: a `MessageChannel` driver contract with mail,
  in-app and web push implementations behind one `ChannelMap`. Channel
  selection, fallback between channels, and per-brand sender identity — from
  address, reply-to, footer, signing domain. Web push (ADR-0041 point 7,
  D-MSG-02) uses one VAPID key pair per deployment, per-device subscription
  rows keyed (recipient, endpoint) pruned when the push service reports them
  gone, and a typed payload (title, body, URL, tag) rendered by the static
  `push-sw.js` worker — never a free-form array.
- **Topics and preferences** (ADR-0041, D-MSG-05/06): modules declare topics at
  boot (key, label, default channels, `suppressible`, `digestable`) in the
  `TopicRegistry`, exactly as they declare templates. A send names its topic;
  `SendMessage` fans out one envelope per default channel intersected with the
  recipient's stored preferences. Preference rows live *here* — the module that
  applies the rule stores it, so an unsubscribe has no consistency window. The
  preference page (`settings/notifications`) renders from the registry: a new
  notification kind is one declaration, no migration, no settings-page edit.
- **Digest windows and quiet hours** (ADR-0041 point 5): a digestable topic's
  send is written `held` — visible in the log, never dropped — with a
  `hold_until` at the end of its 30-minute window per (recipient, channel);
  quiet hours (22:00–08:00 in the recipient's timezone, the brand's Messaging
  settings then the application timezone as fallbacks) push it to 08:00. The
  `messaging:flush-held` sweep (every minute) sends one due hold as itself and
  coalesces several into one `messaging.digest` message listing each item's
  rendered subject; member rows keep `digest_message_id` so "did they get it"
  stays answerable. `urgent` bypasses both.
- **Templates**: template keys registered by the owning module at boot,
  per-brand overrides, localisation per recipient locale, and rendering against
  a typed payload rather than a free-form array (ADR-0009,
  lessons-from-lancore #11). Every registered `MessageTemplate` carries a
  `sample` — a realistic payload for its variables — used for nothing but
  the template editor below; a real send always renders against the caller's
  own typed payload.
  **The template editor** (ADR-0048 decisions 4 and 6, Task 8,
  `/admin/messages/templates`, gated `ManageMessageTemplates`): an index of
  every registered key with an override badge per locale for the console's
  selected brand, and an edit screen with the module default and the
  brand's own rewrite side by side per locale (locale tabs), a debounced
  live preview (`renderString()` + `MailLayout::wrap()` against the
  template's `sample`, sandboxed `<iframe srcdoc>`), and a reset back to
  the default. Saving (`Actions\SaveTemplateOverride`) validates the Blade
  first by rendering the sample — a compile error becomes a validation
  error on `body`, never a 500 — before writing the row
  (`(brand_id, template_key, locale)`, either field individually nullable
  and falling back to the default). **Send test mail to me**
  (`Actions\SendTestMail`, template `messaging.test-mail`): a "How your
  mail looks" card on the mail-settings edit page shows the resolved
  `BrandMailIdentityData` (logo, accent colour, seller line, imprint/
  privacy links) and a button that sends that render to the signed-in
  operator's own account email — never a domain event, and gated
  `ManageSenderIdentity` since it is a mail-settings action, not a
  template-editor one.
- **Branded layout and mail identity** (ADR-0048 point 4): every mail
  `MailChannel` sends is wrapped in one layout (`resources/views/layouts/
  branded.blade.php`, table-based, inline CSS, 600 px) before it reaches
  `TemplatedMail` — a template author writes only the body. The layout shows
  the brand's logo (or its name as text, when it has none) on its accent
  colour, the rendered body, and a footer with the operator's legal line,
  imprint/privacy links, and — only for a template sent with a topic — a
  link to manage notification preferences; contractual/legal mail (no
  topic) carries none, since only the suppression list applies to it. A
  generated plain-text alternative rides beside the HTML part (subject,
  the body with `<p>`/`<br>` turned into line breaks and every other tag
  stripped, then the same footer lines).
  The identity is a read model (`Data\BrandMailIdentityData`) resolved
  fresh per send from the envelope's own `brandId` by
  `Branding\BrandMailIdentityResolver` — never a singleton holding a brand
  (Octane): logo and alt text from Platform's `BrandIdentityResolver`,
  accent colour from `Brand::theme_tokens['accent']`/`['on-accent']` with
  the theme catalogue's defaults as fallback, the legal line from
  `OperatorSettings::sellerForBrand()` (a brand with no operator degrades to
  the brand name rather than failing the send), and the imprint/privacy
  links from Platform's `Footer\FooterLinks` service — a plain read service,
  not the `FooterLink` model, which stays off Messaging's cross-module
  allow-list. The preferences link resolves against `notification-
  preferences.edit` when that route exists and the send names a topic,
  absolute against the brand's own host where `BrandUrl` can resolve one
  (a queue job has no request to derive it from); it is left off entirely
  once no such route exists yet.
  `SendMessage` dispatches `SendQueuedMessage` with `->afterCommit()`: a
  send accepted inside the caller's own transaction (an order placed, a
  registration confirmed) only actually reaches the queue once that
  transaction commits, so a rollback sends no mail for a change that never
  happened.
- **In-app notification inbox** (ADR-0041 point 6): the in-app channel's
  delivery *is* the `messaging_notifications` row (title, body, optional URL,
  topic, read state). The bell lives in the persistent layout and fetches its
  JSON feed once per browser page load — never on every request
  (lessons-from-lancore #5) — then stays current via `notification.received`
  broadcast on the recipient's private channel over Reverb (ADR-0025).
  Rendering-time filtering for inactive modules (D-BATCH-7) applies at read,
  not at send.
- **Delivery log**: attempts, provider message identifiers, deliveries, bounces
  and complaints, plus the suppression list built from hard bounces and spam
  complaints. Infrastructure, not a domain record — it exists so a support
  question about "did they get the mail" has an answer. The suppression list is
  per brand, not global, because ADR-0028 makes reputation per brand domain: a
  hard bounce on one brand's sending domain says nothing about deliverability on
  another's. `messaging_messages` carries two more columns for this:
  `attachments` (a list of `{disk, path, as, mime}`, what actually rode on the
  envelope — `SendMessage::log()` writes it from the request's attachment
  collection) and `resent_from_id`, set on a resend's fresh row to point back
  at the record an operator resent.
  The operator console (ADR-0048 decision 6, `/admin/messages`, gated
  `ViewMessageLog`) is the screen this exists for: an index filtered by
  brand/status/channel/template and searched by recipient email, and a detail
  page showing the meta, the payload, the rendered mail (sandboxed
  `<iframe srcdoc>`, redacted to `bodyRedacted: true` without
  `ViewMessageContent` regardless of whether the row stored a body) and the
  attachment names. `Actions\ResendMessage` (gated `ResendMessage`, its own
  capability — seeing the log does not imply sending again) rebuilds a
  `SendMessageRequest` from the stored row — payload wrapped in the new
  `Data\ArrayPayloadData` (the row kept only the rendered array, not the
  original module-owned Data class, and Messaging may not import a module's
  own payload type anyway), attachments re-checked against disk and dropped
  with a warning when gone — and hands it back to `SendMessage`, which logs a
  new row exactly like an original send (`MessageQueued` fires again); the new
  row's `resent_from_id` links it to the one it came from. `urgent: true` on a
  resend, so it bypasses digest/quiet-hours holds rather than joining an
  unrelated window. Refuses (`LogicException`, mapped to a redirect with
  errors) a record still `Queued`/`Held` — nothing to resend yet.
- **The outbound pipeline**: its own queue name, retry and backoff policy, and
  the brand context carried into and restored inside the job (ADR-0010).
- **Rendering context**: the brand read model — name, canonical domain, sender
  identity, theme tokens for mail — and the recipient read model — display name
  and locale — both maintained from other modules' events.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Whether a person wants a given notification | Messaging itself (ADR-0041 revised this line): preferences are rows per recipient/topic/channel stored here — Identity's settings navigation links to Messaging's preference page |
| Email address of record | Identity for accounts, Commerce for invoice recipients — Messaging resolves a recipient by ULID at send time |
| The business condition that triggers a message | the module owning the process — Messaging never watches for reasons to send, with one deliberate exception (ADR-0041 point 8): `AnnouncementPublished` and `DunningLevelRaised` *address* Messaging without naming a template ("Messaging alone decides what this means"), so for exactly those two the listener, topic and wording live here |
| Campaigns, subscriber lists, bulk marketing | Newsletter, behind a Listmonk client contract |
| Legal consent for marketing contact | Compliance holds the consent record; Messaging enforces deliverability from it |
| Chat, presence, direct messages between participants | Community |
| News articles and announcements | Content — Messaging may deliver a notification about one, the article stays there |
| Brand theme tokens and their contrast validation | Platform (ADR-0008) — Messaging consumes tokens for mail rendering |
| Audit trail of who sent what | Compliance, from Messaging's contract events |
| Ticket and badge documents | Admission — Messaging attaches what it is handed |

The boundary that will be tested first: someone will want a listener in
Messaging on `OrderPaid` to send a receipt. That listener belongs in Commerce,
which then calls Messaging. Otherwise Messaging ends up knowing every module's
event catalogue, which is exactly the shape LanCore's god objects took. The
intent-event exception above is narrow on purpose: it applies only where the
emitting module's own contract declares that Messaging owns the
interpretation — `OrderPaid` declares no such thing.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

Most rows below are still a proposal and become binding when the event class
lands; `MessageQueued` has landed and its payload shape below is the binding
one.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `MessageQueued` | A message is accepted for delivery | `message_id`, `brand_id`, `recipient_id`, `channel`, `template_key` | implemented |
| `MessageDelivered` | The transport accepts the message (provider callbacks may refine later) | `message_id`, `channel`, `provider_message_id`, `delivered_at` | implemented |
| `MessageFailed` | An attempt fails and will or will not be retried | `message_id`, `channel`, `failure_code`, `attempt`, `will_retry` | implemented |
| `MessageBounced` | A provider reports a bounce | `message_id`, `recipient_id`, `bounce_type`, `bounced_at` | proposed |
| `MessageSuppressed` | A channel's send is refused before dispatch (preferences or suppression list) | `message_id`, `brand_id`, `recipient_id`, `template_key`, `channel`, `reason_code` | implemented |
| `RecipientSuppressionAdded` | An address or recipient joins the brand's suppression list | `suppression_id`, `brand_id`, `recipient_id`, `email`, `channel`, `reason_code`, `suppressed_at` | implemented |
| `RecipientSuppressionLifted` | A suppression is lifted | `suppression_id`, `brand_id`, `recipient_id`, `email`, `channel`, `lifted_at` | implemented |
| `NotificationRead` | An in-app notification is marked read (first read only — re-reads emit nothing) | `notification_id`, `recipient_id`, `read_at` | implemented |

`MessageSuppressed` matters more than it looks: a module that believes it sent a
confirmation needs a way to learn that the platform declined to.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `UserRegistered` | Identity | Seed the recipient read model — ULID, display name, locale |
| `UserProfileUpdated` | Identity | Keep display name, locale and timezone current for rendering |
| `UserAnonymized` | Identity | Drop the recipient read model entry |
| `AnnouncementPublished` | Content | Intent event (ADR-0041 point 8, D-CNT-08): fan out to every account on the `content.announcements` topic; urgent bypasses digests and quiet hours |
| `DunningLevelRaised` | Commerce | Intent event (ADR-0041 point 8): mail the billing contact a payment reminder on the non-suppressible `commerce.dunning` topic |
| `BrandCreated` | Platform | Seed the brand read model — name, canonical domain, sender identity |
| `BrandThemeUpdated` | Platform | Refresh the tokens used to render branded mail |
| `BrandArchived` | Platform | Stop delivery for an archived brand |
| `DataExportCompleted` | Compliance | Notify the subject that the archive is ready |
| `UserDeletionRequested` | Compliance | Purge inbox and log rows through Messaging's own `Gdpr/` handler |

All projections here must be idempotent and replayable: a read model rebuilt
from scratch has to produce the same rendering context (ADR-0004).

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewMessageLog`, `ViewMessageContent`, `ResendMessage`,
`ManageMessageTemplates`, `ManageSenderIdentity`, `ViewSuppressionList`,
`ManageSuppressionList`. Seeing that a message was sent and reading its rendered
body are separate capabilities, since the body usually contains personal data.

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. A brand
cannot switch off transactional messaging without breaking password resets,
confirmations and legal notices. Two adjacent things *are* configurable as data
and should not be confused with activation: which channels a brand has
configured, and whether an individual template key is enabled for it.

## Open questions

- ~~Does each brand get its own mail transport and credentials, with SPF and
  DKIM on its own domain, or does one shared transport send on behalf of all
  brands?~~ Settled by ADR-0028: each brand carries its own transport
  configuration (or falls back to the application's default mailer when
  unconfigured), resolved from BrandContext at send time. Consequence beyond
  the transport itself: the suppression list (Backlog, see "Owns" above) is
  per brand, not global — a bounce on one brand's domain says nothing about
  another's.
- ~~Does web push ship at release, or are mail plus in-app enough for the
  Phase 2 definition of done?~~ **Decision log Round 6 (D-MSG-02, user
  override):** ships at release — a push `MessageChannel` driver joins mail
  and in-app.
- ~~Digests, batching and quiet hours: a shift plan changing five times in an
  hour should not produce five mails. Is that a Messaging concern or the
  emitting module's?~~ **Decision log Round 6 (D-MSG-03, user override):**
  Messaging's concern — it owns digest windows and quiet hours as
  infrastructure. Modules keep sending per-change; Messaging coalesces.
- ~~How template payloads are versioned when the emitting module changes
  shape — a queued message may render after a deploy that changed its payload
  type.~~ **Decision log Round 7 (D-BATCH-7):** template payload Data classes
  change additively only; a breaking shape change requires a new template
  key, protecting queued messages across deploys.
- ~~How long the delivery log is kept, and whether that retention is
  Messaging's own decision or a rule published by Compliance. **Still open**
  after the Round 6 gate review — pairs with Compliance's own open
  audit-retention question, since both are "how long do we keep an
  infrastructure record" questions that likely want the same answer.~~
  **Decision log Round 7 (D-CPL-01):** retention is a Compliance-published
  rule — delivery log 12 months, audit trail 36 months.
- ~~Whether an in-app notification for a module that is inactive for a brand
  is suppressed at send time or filtered at render time.~~ **Decision log
  Round 7 (D-BATCH-7):** filtered at render time, not suppressed at send.
