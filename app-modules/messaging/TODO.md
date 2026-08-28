# Messaging — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] `UserAnonymized` is still unconsumed by the recipient read model — it
      lands with Identity's GDPR handler.
- [ ] Suppression-list operator screen: `AddSuppression`/`LiftSuppression`
      exist with `ViewSuppressionList`/`ManageSuppressionList` catalogued, but
      lifting still means tinker. (The delivery-log half of this item is done
      — see Done below.)
- [ ] Provider bounce/complaint webhooks feeding `AddSuppression` (ADR-0041
      point 4 follow-up): per-brand transports are plain SMTP today and emit
      none, so only the seam exists. `MessageBounced` in SPEC.md stays
      proposed until this lands.
- [ ] A brand read model — name, canonical domain, theme tokens for mail —
      maintained from `BrandCreated` / `BrandThemeUpdated` / `BrandArchived`
      (SPEC.md "Consumes"). Sender identity and transport do **not** need this:
      ADR-0028 makes them Messaging-owned config data
      (`messaging_brand_settings`), operator-managed until a settings UI
      exists, not a projection of Platform events — this item is now only
      about the rendering-context fields (domain, theme tokens), narrower than
      originally scoped.
- [ ] Fix `tests/Unit/ArchitectureTest.php`'s "never imports another module
      Eloquent model" check (root test, out of `app-modules/messaging/`
      scope): it flags `use Konventix\Platform\Models\Concerns\BelongsToBrand;`
      as a foreign-model import in every module but Platform, because its
      allow-list only exempts `Models\Brand`, `Models\Role`, `Models\Permission`
      by exact class, not the `Models\Concerns\*` traits ADR-0010 requires
      every brand-owned model to use. Pre-existing before this change (already
      true for `event` and `compliance`'s models); this module's
      `BrandMailSettings` and `TemplateOverride` add two more instances of the
      same, correct, pattern. Needs a fix in the shared test, not in any
      individual module.

## Backlog

- [ ] Announcement audience narrowing: both `everyone` and `logged-in`
      currently reach every known account, operator-wide — Messaging's
      recipient read model has no notion of brand membership. Needs an
      Identity contract signal (brand first-seen / membership) before the
      fan-out can respect the brand boundary of the announcement.

- [ ] Push end-to-end against a real browser: the channel, worker and
      subscription flow are tested on fakes; a manual pass against Chrome and
      Firefox (permission prompt, delivery with the site closed, endpoint
      rotation) still needs doing once a deployment has VAPID keys.
- [ ] Recipient timezone is never populated: Identity's contract events carry
      no timezone field, so quiet hours always fall back to the brand's
      Messaging settings (or the app timezone) today. Needs
      `UserProfileUpdated` (or a dedicated event) to gain the field, plus
      `ProjectRecipient` projecting it.
- [ ] Held messages with attachments are deliberately not held (attachments
      are referenced on the envelope but not persisted on the log row, so a
      flush would lose them). If a digestable topic ever legitimately carries
      attachments, the log row needs to store the references.
- [ ] D-BATCH-7 render-time filtering for inactive modules in the inbox: the
      read endpoints do not yet skip notifications whose topic belongs to a
      module inactive for the brand — harmless today because every module
      that declares topics is a kernel/core module (always active), but the
      filter needs module attribution on topics before a gateable module
      declares one.
- [ ] Recipient and preference read models from Identity events — idempotent
      and replayable, rebuildable from scratch. (Superset of the recipient
      read model item under Next above; that item is the minimum slice —
      email/locale — needed to drop the temporary `SendMessageRequest` fields.)
- [ ] The `src/Gdpr/` handler that exports and purges inbox and log rows
      (`ManageMessageTemplates`/`ViewSuppressionList`/
      `ManageSuppressionList` are the remaining capabilities with no UI yet;
      `ManageSenderIdentity` gates the mail-settings console and
      `ViewMessageLog`/`ViewMessageContent`/`ResendMessage` gate the delivery
      log — see Done for both).
- [ ] ResendMessage accepts the operator's user id but only logs it; a
      resent_by column (or an AuditRecorder entry, as
      RegistrationAdminController::export() does) is owed once resend
      accountability must be queryable.
- [ ] Consider whether `BrandCreated` should auto-seed an "unconfigured"
      `messaging_brand_settings` row per brand (so every brand always has
      exactly one row) versus the current design, where absence of a row
      *means* unconfigured and `MailChannel` already handles that by falling
      back to the default mailer. No behavioural gap today — this is a
      query-ergonomics question, not a correctness one.

## Done

- [x] ADR-0048 decisions 4 and 6, Task 8 of the mail-and-receipts tranche:
      operators rewrite a mail in their own words, see it before it goes,
      and send themselves one. `/admin/messages/templates` (nav "Message
      templates", order 42, gated `ManageMessageTemplates`) —
      `Http\Controllers\Admin\TemplateAdminController::index()` lists every
      key `TemplateRegistry::all()` (new) knows about with an override badge
      per locale for the console's selected brand; `edit()` needs a brand
      picked first (redirects to the index with a toast otherwise) and shows
      the module default and the brand's own rewrite side by side per
      locale, locale tabs, a 500ms-debounced live preview
      (`preview()`, POST, JSON — an XHR from the page, not an Inertia visit)
      rendered inside the branded layout via a new
      `TemplateRenderer::renderString()` (no override lookup — previews a
      draft, not what is stored) against the template's new `sample` field
      (a realistic payload every registered template now carries, used for
      nothing but this and the compile check below), and "Reset to default"
      per locale once an override exists. `Actions\SaveTemplateOverride`
      validates the Blade first by rendering the sample — a compile error
      (a glued directive left as literal text, `.ai/rules/app-modules-src.md`,
      is the common way to get one) becomes a validation error on `body`
      rather than a 500 — before writing the row
      (`(brand_id, template_key, locale)`, updateOrCreate semantics, either
      field individually nullable and falling back to the default);
      `Actions\ResetTemplateOverride` deletes it. Mail-settings gained its
      own piece: `MailSettingsAdminController::edit()` now also hands the
      page a `BrandMailIdentityData` (the exact read model a real send
      resolves), rendered as a "How your mail looks" card — logo, accent
      swatch with its value, seller line, imprint/privacy links — next to a
      "Send test mail to me" button (`POST /mail-settings/{brand}/test`,
      gated `ManageSenderIdentity` since it lives on that screen, not the
      template editor) that calls `Actions\SendTestMail` with template
      `messaging.test-mail`, addressed to the signed-in operator's own
      account email — never a domain event. `DryRunTest` walks the index and
      the edit route; the edit route legitimately redirects during the walk
      (the console selection it needs is never made by a route-table crawl)
      and is allow-listed for `<400` rather than exactly `200`.

- [x] ADR-0048 decision 6, Task 7 of the mail-and-receipts tranche: the
      delivery log gets a screen, and a lost mail gets a second chance.
      `/admin/messages` (nav "Messages", order 41, gated `ViewMessageLog`) —
      an index (`Http\Controllers\Admin\MessageLogAdminController::index()`)
      filtered by brand/status/channel/template and searched by recipient
      email, a detail page (`show()`) with the meta, the payload, the
      rendered mail in a sandboxed `<iframe srcdoc>` and the attachment
      names. Reading the rendered subject/body needs `ViewMessageContent`
      separately from seeing the row at all (`ViewMessageLog`) —
      `Data\MessageLogDetailData::ofModel()` returns `bodyRedacted: true`
      and no content for a viewer without it, regardless of whether the row
      stored one. `Actions\ResendMessage` (its own capability,
      `ResendMessage`) rebuilds a `SendMessageRequest` from the stored row
      and hands it back to `SendMessage`, which logs a fresh row exactly
      like an original send; the new row's `resent_from_id` (new column,
      alongside `attachments` — what actually rode on the envelope,
      `SendMessage::log()` now writes both) links it back. The row kept
      only the payload's rendered array, not the module-owned Data class
      that produced it — and Messaging may not import a module's own type
      anyway (ADR-0004) — so the resend wraps it in a new
      `Data\ArrayPayloadData`, a `Data` stand-in whose `toArray()` is the
      only thing either consumer (`TemplateRenderer`, `SendMessage::log()`)
      ever calls on a payload. Attachments are re-checked against disk and
      dropped with a warning when gone. `urgent: true` on every resend, so
      it bypasses digest/quiet-hours holds instead of joining an unrelated
      window; refuses (`LogicException`, mapped to a redirect with errors)
      a record still `Queued`/`Held`. `DryRunTest` walks the index; the
      show route is named-skipped — `konventix:seed` sends no mail, so no
      brand ever has a row for it to point at.

- [x] ADR-0048 point 5, Task 6 of the mail-and-receipts tranche: Identity's
      auth mail — `identity.verify-email`, `identity.password-reset`,
      `identity.welcome` — is Messaging's first Identity consumer, closing
      the "wire a real caller" item this file used to open with (stale by
      the time it landed: Commerce and Registration, Tasks 4/5, were
      already real callers). `Konventix\Identity\Mail\AuthMailer` calls
      `SendMessage` with explicit `recipientEmail`/`recipientLocale`
      alongside `recipientId`, independent of whether `ProjectRecipient`'s
      projection has run yet for a brand-new account.

- [x] ADR-0048 point 4, Task 1 of the mail-and-receipts tranche: every mail
      wears a branded layout Messaging owns. `MailChannel` wraps the
      template's rendered subject/body in `messaging::layouts.branded`
      (`Mail\MailLayout::wrap()`) before building `TemplatedMail`, which now
      takes pre-rendered `html`/`text` rather than a bare `RenderedMessage`
      — `Content`'s `text` slot only accepts a view name, so a tiny
      `messaging::mail.text` view echoes the string `MailLayout` derives
      (subject, the body with `<p>`/`<br>` turned into newlines and every
      other tag stripped, then the footer lines). The identity is
      `Data\BrandMailIdentityData`, resolved fresh per send by
      `Branding\BrandMailIdentityResolver` — never a singleton holding a
      brand — from `Brand` (the one Platform model on the cross-module
      allow-list) plus Platform's own non-model read services
      (`Branding\BrandIdentityResolver` for the logo, `Operators\
      OperatorSettings` for the legal line, and the new
      `Konventix\Platform\Footer\FooterLinks` for the imprint/privacy
      links — `FooterLink` itself stays off Messaging's allow-list, so this
      is the sanctioned doorway, same shape as `OperatorSettings`). Accent
      colour comes from `Brand::theme_tokens['accent']`/`['on-accent']` with
      the theme catalogue's defaults as fallback; a brand with no operator
      degrades to its name as the seller line instead of failing the send.
      The preferences link renders only for a template sent with a topic,
      against the real `notification-preferences.edit` route (the brief
      assumed a `messaging.preferences.*` name; the settings page Slice 13a
      shipped is named `notification-preferences.edit`, so that is what
      resolves), absolute against the brand's own host via `BrandUrl` where
      one exists. `SendMessage` now dispatches `SendQueuedMessage` with
      `->afterCommit()`, so a send accepted inside the caller's own
      transaction never reaches the queue if that transaction rolls back —
      the gap ADR-0048 calls out explicitly. Existing mail (Commerce's
      invoice mail, the intent-event listeners) renders inside the new
      layout unchanged; their own tests still pass. ADR-0048 committed
      verbatim at `docs/decisions/0048-mail-and-the-rechnung-on-payment.md`.

- [x] `permissions:sync` exists (it shipped with the operator console) and,
      since ADR-0045, also materializes each module's default roles per
      brand.

- [x] Backfill for accounts created before the recipient projection existed
      closed: `identity:replay-profiles` re-emits `UserProfileUpdated` for
      every account, so a subject with no recipient row now gets one, and
      id-only sends work for old accounts too. Identity's command rather
      than a `messaging:rebuild-recipients` of our own — Content's author
      read model had the same gap and one replay closes both.

- [x] The inbox as a page (`/notifications`), paginated, read and unread
      together; the bell's JSON feed moved to `/notifications/feed` so the
      human-facing path is the one a link lands on. Both write endpoints now
      answer either reader — JSON to the bell's `fetch`, a redirect back to
      the page's Inertia visit. The bell gained a per-item mark-read control
      and a link to the page; there is deliberately no dismiss/archive
      concept and no schema change behind it.
- [x] Mail-settings console (`/admin/mail-settings`, gated by
      `ManageSenderIdentity`): per-brand sender identity, quiet-hours
      timezone (ADR-0041) and optional own SMTP transport (ADR-0028) —
      index over every brand with its transport state, edit form whose
      stored password never travels to the browser and survives a blank
      re-submit. First Messaging entry in the operator navigation.
- [x] Intent-event listeners (Slice 13e, ADR-0041 point 8, closes
      Slice 13): `SendAnnouncementNotifications` (queued on the messaging
      queue) fans `AnnouncementPublished` out to every account on the
      `content.announcements` topic (in-app + push + mail, digestable —
      urgent bypasses holds per D-CNT-08); `SendDunningNotice` mails the
      billing contact on `commerce.dunning` (non-suppressible, mail-only;
      German wording default like the invoice mail). Both topics and both
      templates (`messaging.announcement`, `messaging.dunning-notice`) are
      Messaging-owned because these two events deliberately leave the
      interpretation here. `DunningLevelRaised` gained billing scalars
      additively (Commerce SPEC row updated) so the listener never reads
      Commerce models. Both SPEC boundary lines now carry the narrow
      exception. Follow-up in Backlog: audience narrowing per brand
      membership.
- [x] Web push (Slice 13d, ADR-0041 point 7, closes D-MSG-02):
      `PushChannel` delivers the typed `PushMessageData` (title, plain-text
      body, URL, tag) to every `PushSubscription` of the recipient through
      the `PushSender` seam (`WebPushSender` wraps minishlink/web-push
      directly; tests run on a fake). Endpoints reported gone (404/410) are
      pruned on the spot; no devices is a no-op, and the send fails only
      when every surviving endpoint refused. One VAPID key pair per
      deployment (`services.webpush`, generated by `messaging:vapid-keys` —
      rotation invalidates all subscriptions). The static `public/push-sw.js`
      worker renders the payload and opens the URL on click; the
      notification settings page owns the per-device enable/disable flow
      against `POST/DELETE /push-subscriptions` (re-subscribing an endpoint
      refreshes its keys).
- [x] In-app inbox (Slice 13c, ADR-0041 point 6): `InAppChannel` delivers by
      writing the `InboxNotification` row (rendered title/body, optional
      `url` taken from a string `url` key in the payload, topic, read state)
      and pushing `NotificationReceived` (`notification.received`,
      `ShouldBroadcastNow`) on the recipient's private Reverb channel — the
      one `routes/channels.php` already authorizes. Bell
      (`messaging/NotificationBell`, mounted in `AppSidebarHeader` via the
      module component registry so the shell holds no module import) fetches
      `/notifications` JSON once per browser page load and listens over
      Echo; mark-read / mark-all-read endpoints call
      `MarkNotificationRead`/`MarkAllNotificationsRead`, which emit
      `NotificationRead` on first read only. `NotificationData` is the
      ADR-0009 single shape for fetched rows and broadcast payloads alike.
- [x] Digest windows and quiet hours (Slice 13b, ADR-0041 point 5, closes
      D-MSG-03): `HoldPolicy` writes a digestable topic's non-urgent send as
      `held` with `hold_until` at the end of its 30-minute window per
      (brand, recipient, channel) — later sends join the open window — and
      quiet hours (22:00–08:00, recipient timezone → brand Messaging
      settings → app timezone) push the end to 08:00. `FlushHeldMessages`
      (scheduled `messaging:flush-held`, every minute, row locks against
      overlapping sweeps) sends a single due hold as itself (payload
      rehydrated from the log row via `RehydratedPayload`,
      `recipient_locale` now stored for exactly this) and coalesces several
      into one `messaging.digest` message (`DigestData`: each member's
      subject rendered in the recipient's locale); members are marked sent
      with `digest_message_id`. Urgent and attachment-carrying sends bypass
      holding.
- [x] Topics, fan-out, delivery log, suppression and preferences (Slice 13a,
      ADR-0041 points 1–4): `TopicRegistry` + `Topic` declared via the new
      `ModuleServiceProvider::registerMessageTopics()` hook; `SendMessage` is
      now the fan-out point — one envelope and one `messaging_messages` log
      row per resolved channel (topic defaults, minus channels without a
      driver or that need an account the recipient lacks), with preference
      and suppression refusals logged `suppressed` + `MessageSuppressed`,
      never silent. `SendQueuedMessage` keeps the log row (`sent`/`failed`,
      attempts, last error) and emits `MessageDelivered`/`MessageFailed`;
      bodies stored only for `logBodies`-flagged templates (D-MSG-07 hybrid).
      Per-brand `Suppression` with partial-unique live rows,
      `AddSuppression`/`LiftSuppression` +
      `RecipientSuppressionAdded`/`Lifted`. `NotificationPreference` rows per
      (recipient, topic, channel) with the `settings/notifications` page
      rendered from the registry (non-suppressible topics render always-on,
      forged toggles ignored). `messaging_recipients.timezone` added
      (nullable, awaits an Identity payload) for quiet hours. A send without
      a topic behaves exactly as before — immediate, mail, no preference
      gate.
- [x] Recipient read model (Slice 2): `messaging_recipients` keyed by the
      user's own ULID (a projection, not an entity — no generated key, not
      brand-scoped per ADR-0016), projected idempotently by
      `Listeners\ProjectRecipient` from Identity's new `UserRegistered` /
      `UserProfileUpdated` contract events. `SendMessage` now resolves
      email/locale from `recipientId` alone; the explicit
      `recipientEmail`/`recipientLocale` fields stayed by design (no longer
      temporary) for account-less recipients (billing contacts,
      `recipientId: null`) and deliberate address overrides. A send that
      resolves to no address is refused loudly.
- [x] Attachments + explicit brand (Slice 2): `AttachmentData`
      (disk/path/as/mime — referenced, not inlined, so job payloads stay
      small) carried through `SendMessageRequest` → envelope →
      `TemplatedMail::attachments()`. `SendMessageRequest.brandId` overrides
      ambient BrandContext for operator/queue-triggered sends; `MailChannel`
      and `TemplateRenderer` now key transport, sender identity and brand
      overrides off the envelope's own `brandId` instead of ambient context
      (the old context-guarded lookup silently fell back to the default
      mailer on brand-less workers).
- [x] First real consumer (Slice 2, closes the exhibitor DoD item):
      Commerce registers `commerce.invoice-issued` (de default + en) via the
      new `ModuleServiceProvider::registerMessageTemplates()` hook and mails
      the sealed ZUGFeRD archive to the billing party from
      `GenerateInvoiceDocument` (`SendInvoiceMail`, replay-safe behind the
      document seal). The root architecture test now sanctions Messaging's
      declared send API surface (`Actions`/`Data`/`Templates`) for other
      modules — models stay forbidden.

- [x] `MessageChannel` driver contract (`src/Contracts/MessageChannel.php`)
      with a mail driver (`src/Channels/MailChannel.php`) as first
      implementation. The send API modules consume is
      `Konventix\Messaging\Actions\SendMessage::handle(SendMessageRequest
      $request)`: recipient ULID, template key, typed
      `spatie/laravel-data` payload — no Eloquent models, no free-form arrays
      (ADR-0009). Type-level enforcement (a plain PHP `Data` type-hint) is
      covered in `tests/SendMessageActionTest.php`.
- [x] Per-brand sender identity and transport (ADR-0028): `BrandMailSettings`
      (`messaging_brand_settings` table, module-owned, keyed by `brand_id`) —
      from-name, from-address, reply-to, plus `transport` under an
      `encrypted:array` cast. `MailChannel` resolves it from `BrandContext` at
      send time and falls back to `config('mail.default')` when a brand has no
      row. Design note: builds a runtime-registered, uniquely-named mailer
      (`config(['mail.mailers.<name>' => $transport])` + `Mail::mailer($name)`)
      rather than `Mail::build($transport)` — both are "mailer on the fly" in
      Laravel's terms, but this form stays compatible with `Mail::fake()` in
      tests (its fake manager implements `Factory::mailer()`, not `build()`),
      letting tests assert the resolved mailer name to prove per-brand
      transport selection.
- [x] Template registry (`src/Templates/TemplateRegistry.php`), registered as
      a singleton the same way Platform registers `NavigationRegistry`;
      modules call `TemplateRegistry::register()` from their own
      ServiceProvider's boot (no module does yet — see Next). Per-brand
      overrides: `TemplateOverride` (`messaging_template_overrides` table,
      unique on brand + template key + locale, subject/body individually
      nullable so a brand can override just one). Localisation: recipient
      locale if the template (or override) defines it, else the template's
      declared default locale. Rendering: `Blade::render()` against a string
      — deliberately not a compiled `.blade.php` view, since a brand's
      override is a database row, not a deploy.
- [x] Outbound pipeline: `SendQueuedMessage` (`src/Jobs/`) on its own
      `messaging` queue (set in the job's own constructor, so every dispatch
      site gets it for free), 3 attempts with 10s/60s/300s backoff
      (documented in the class docblock). Brand context: relies entirely on
      Platform's `PropagatesBrandContextToQueue`, not duplicated here.
      `MessageQueued` (`src/Events/`, scalar payload: `messageId`, `brandId`,
      `recipientId`, `channel`, `templateKey`) fires from `SendMessage` when
      the job is accepted, not from the job itself.
- [x] `src/Enums/Permission.php`: `ViewMessageLog`, `ViewMessageContent`,
      `ResendMessage`, `ManageMessageTemplates`, `ManageSenderIdentity`,
      `ViewSuppressionList`, `ManageSuppressionList` — catalogue only, no
      `permissions:sync` run yet (see Backlog), no policies/routes/nav
      consuming them yet (nothing to gate: this module ships no controllers).
