# Participant tickets and calendar files — design

**Issue:** #72 (Print- und Digitaltickets plus ICS). Related: #68
(environment ribbon, ADR-0053), #23 (badge template editor), #98 (entrance
scan), #128 (wallet, out of scope).

**Status:** binding contract for the implementation plan. Deviations found
during implementation are appended to §13, never edited into the body.

---

## 1. What this builds

A participant can download a ticket as a PDF and add the event to their
calendar. Today neither exists: `registration/my/Show.vue` offers no ticket,
no PDF and no calendar link, and the repository contains no iCalendar support
at all.

Three deliverables:

1. **A ticket PDF** — one A4 page per admission, carrying the scannable KVX1
   code, a brand mark, a forensic watermark and a TESTSYSTEM overlay off
   production.
2. **A calendar file** — an RFC 5545 `.ics` for an event, attached to the
   confirmation mail and downloadable from the public event page.
3. **A stable ticket code** — the admission's token stops changing on every
   issue, and a superseded token is refused at the door.

---

## 2. What already exists (verified 2026-09-11)

- **PDF rendering** is `barryvdh/laravel-dompdf` ^3.1 driving a Blade view.
  Two precedents: `Konventix\Commerce\Documents\InvoiceDocumentRenderer`
  (rendered once, sealed to a disk, GoBD) and
  `Konventix\Admission\Documents\BadgeDocumentRenderer` (rendered fresh,
  stored nowhere, streamed to the response).
- **QR codes** are `bacon/bacon-qr-code` ^3.0 written through
  `SvgImageBackEnd` and embedded as a `data:image/svg+xml;base64` URI.
  `BadgeDocumentRenderer::qrDataUri()` is the existing helper.
- **The scannable credential** is the KVX1 token:
  `KVX1.<kid>.<body>.<sig>`, Ed25519, body
  `{tid, evt, nonce, iat, exp, days}`, minted per Admission by
  `IssueAdmissionToken` with a 60-day lifetime and verified by
  `VerifyAdmissionToken` through `AdmissionTokenCodec`. Signing keys are
  per brand in `entrance_signing_keys`, private key encrypted at rest,
  rotated by `retired_at`/`expires_at`.
- **An Admission is one person's access right.** One Registration grants N
  Admissions (ADR-0005). `admissions` carries `registration_id`,
  `event_id`, `holder_user_id` (nullable), `role`, `valid_from`,
  `valid_until`, `state`.
- **Mail attachments already work.** `Konventix\Messaging\Data\AttachmentData`
  names a disk and a path; `TemplatedMail::attachments()` turns it into
  `Attachment::fromStorageDisk(...)`. The live consumer is
  `Konventix\Commerce\Actions\SendInvoiceMail`.
- **The confirmation mail** is `MailOnRegistrationConfirmed` →
  `RegistrationMailer::send($registration, 'registration.confirmed', ...)` →
  Messaging's `SendMessage`.
- **UI extension points** are `Konventix\Platform\Slots\SlotRegistry`. The
  participant's registration page renders the `registration.mine.show` slot
  (Resources' seat summary and Commerce's settlement card are the two live
  contributions); the public event page renders `event.landing`.
- **An event's time and place**: `events.starts_at`, `events.ends_at` are
  wall clock with no offset, `events.timezone` is IANA, and
  `startsAtInstant()` / `endsAtInstant()` produce instants (ADR-0027).
  Venues hang off the `event_venues` pivot with `is_primary`. `event_days`
  carries dates without times. The agenda module is an empty scaffold, so
  an event has no sub-sessions.
- **The environment fact** is `Konventix\Platform\Environment\DeploymentEnvironment::isProduction()`
  (ADR-0053). Nothing else may read `env('APP_ENV')`.
- **Brand identity**: `Brand::$logo_media_id` / `$logo_dark_media_id`,
  resolved to a data URI by `Konventix\Platform\Media\MediaLibrary::dataUriFor()`.
  **`Operator` has no logo** — only `name` and `legal_name`.

### 2.1 A gap found while designing this

`IssueAdmissionToken`, `AdmissionTokenPayload` and `IssueBadge` all state in
their docblocks that reissuing a token strands the previously printed QR.
**It does not.** No nonce is stored on `admissions`, neither `RecordScan`
nor `VerifyAdmissionToken` compares one, and
`CheckInRejectionReason::NonceSuperseded` exists but is referenced nowhere.
Every token ever minted for an admission stays valid for its full 60 days.

Today only an operator prints a badge, so little turns on it. A
participant-facing ticket makes it live: each download would mint another
live credential. §5 closes it.

---

## 3. What LanCore did, and what we take

From `docs/inventory/commerce-registration-admission.md`:

| LanCore | Verdict there | Taken here |
| --- | --- | --- |
| TKT-F-027 tri-fold A4 ticket: event hero, QR + holder, legal text + org footer | Generalize | Yes, as three stacked bands on an A4 portrait page rather than three folding panels — this is read on a phone and printed at home, not folded |
| TKT-F-029 forensic watermark: holder, event, venue, org, type, ticket id at ~12% opacity, diagonally repeated, deterministically seeded, clear rectangle around the QR | Port as is | Yes, §4.3 |
| TKT-F-018 deterministic nonce with a stored HMAC hash, plaintext never stored | Port as is | Yes, §5 |
| TKT-F-026 download and QR never rotate the token | Port as is | Yes, §5 |
| TKT-F-024 self-service token rotation by the holder | Port renamed | **No** — out of scope, §12 |

---

## 4. The ticket document

### 4.1 Identity and ownership

One ticket page per **Admission**. The KVX1 token already in use is the
ticket code; nothing new is minted and no new column carries it.

Two download routes, both authenticated:

- `GET /my/registrations/{registration}/ticket` — the buyer's file, one page
  per admission the registration granted, ordered by the admission's
  creation. Authorised by `registrations.registrant_user_id === auth id`,
  the same inline ownership check `MyRegistrationsController::show` already
  makes. 403 otherwise.
- `GET /my/tickets` and `GET /my/admissions/{admission}/ticket` — a holder's
  own list and their single-page file, authorised by
  `admissions.holder_user_id === auth id`. An admission with no linked
  account simply does not appear; its ticket reaches that person through the
  buyer's file.

Both refuse an admission in a revoked state, and an admission whose
registration exists and is not confirmed. `registration_id` is nullable —
a staff admission has none — and such an admission is ticketable on its own. A registration with no admissions yet renders
no file and the link does not appear.

### 4.2 Layout

A4 portrait, one page per admission, three stacked bands:

1. **Event band** — brand logo, event name, the event's local start and end
   as wall clock with the timezone named, and the primary venue's name and
   address.
2. **Holder band** — the QR at 320 px nominal, the holder's name (falling
   back to the registration's billing recipient, then "Unassigned", as the
   badge does), the admission role, the days the token is valid for, and the
   admission id in a door-typeable short form.
3. **Footer band** — the operator's legal name, the brand name, the ticket
   id, and one line naming where the terms live. The full purchase
   conditions are **not** printed: they would add pages and the buyer
   already consented at checkout.

Styling uses the platform's semantic tokens the way the invoice and badge
views do. No external assets: every image is a data URI, so dompdf never
makes a network request.

### 4.3 Watermarks

Three layers, in this order from back to front:

1. **Brand mark** — the brand logo at low opacity, centred, one per page.
   Absent when the brand has no logo; the page must still render.
2. **Forensic repeat** — holder name, event name, venue, operator legal
   name, admission role and the admission id, set at about 12 % opacity and
   repeated diagonally across the page. Deterministically seeded from the
   admission id so two renders of the same ticket are visually identical.
   A clear rectangle is kept around the QR so the repeat never degrades a
   scan.
3. **TESTSYSTEM** — a single diagonal band, drawn only when
   `DeploymentEnvironment::isProduction()` is false. This satisfies the
   criterion ADR-0053 §5 left open on #68.

The watermark layers live in a **Platform** service, not in Admission, so
the badge (#23) and the invoice can adopt the same one later. ADR-0053 §5
says the environment service "is what a renderer will ask"; this is that
renderer. Admission is its first caller.

The operator has no logo, so "operator watermark" in the issue is met by the
operator's legal name in the forensic repeat and the footer, not by an
image. Recorded here because the issue's wording implies an image.

### 4.4 Rendering and delivery

**Rendered fresh on every request, stored nowhere**, like the badge and
unlike the invoice. The ticket has no retention duty, and a sealed copy
would be wrong the moment a seat is picked, a holder is named or an event
time moves. The response streams the bytes with
`Content-Type: application/pdf` and a filename built from the event slug.

No queue, no job, no disk. A render failure is a 500 and is already covered
by the existing HTTP and error-log alerts.

---

## 5. A stable ticket code

`admissions` gains two nullable columns:

- `token_nonce_hash` — HMAC-SHA256 of the token's nonce, keyed with a
  dedicated pepper. The plaintext nonce is never stored, following
  TKT-F-018.
- `token_issued_at` — when the current token was minted, for support
  questions and for the delta feed's ordering.

The pepper is read from config, defaulting to a value derived from the
application key so no deployment needs a new environment variable before it
can upgrade.

**`IssueAdmissionToken` becomes explicit about intent.** It gains two entry
points rather than one:

- `current(Admission $admission, array $dayIds = []): string` — returns a
  token bearing the admission's current nonce when one is stored, and mints
  and stores one when none is. This is what a ticket render, a badge print
  and any other read path calls. Calling it twice yields the same nonce, so
  a downloaded ticket stays valid.
- `rotate(Admission $admission, array $dayIds = []): string` — mints a fresh
  nonce, stores its hash, and writes a `token_superseded` delta entry. Only
  write paths call it.

The existing `handle()` is removed rather than left as an ambiguous third
door; every current caller is moved to `current()`.

**Enforcement.** `VerifyAdmissionToken` gains the comparison: a token whose
nonce hash differs from the admission's stored hash is refused with the
existing `CheckInRejectionReason::NonceSuperseded`. An admission with no
stored hash accepts any otherwise-valid token, which is what keeps tokens
already in circulation working through the deploy.

**Offline.** `entrance_delta_entries` gains a nullable `payload` JSON
column, and a `token_superseded` entry carries the new hash. A device stores
hash-per-admission and refuses a mismatch. A device that has never seen an
admission has no hash and therefore accepts — failing open offline is
deliberate: a fresh scanner must not reject a whole queue. Online
verification always reads the database and is exact.

---

## 6. The calendar file

### 6.1 Format

Hand-rolled, no new dependency, consistent with the seating decision. One
`VCALENDAR` holding one `VEVENT`, because an event has no sub-sessions.

- `VERSION:2.0`, `PRODID` naming Konventix, `CALSCALE:GREGORIAN`,
  `METHOD:PUBLISH`.
- `UID` is stable and derived from the event id plus the brand's host, so
  re-importing updates the same entry instead of duplicating it.
- **`DTSTART` and `DTEND` are UTC instants** (`…Z`), taken from
  `startsAtInstant()` / `endsAtInstant()`. No `TZID`, therefore no
  `VTIMEZONE` block. A correct `VTIMEZONE` needs full daylight-saving rules;
  a UTC instant needs none and renders correctly in every client's own
  timezone. Recorded as a decision because the event stores wall clock plus
  an IANA zone and the obvious reading would be to emit `TZID`.
- `SUMMARY` is the event name. `DESCRIPTION` is the event description,
  plain text. `LOCATION` is the primary venue's name and address on one
  line. `URL` is the public event page.
- `DTSTAMP` is the moment of generation; `LAST-MODIFIED` is the event's
  `updated_at`. No `SEQUENCE`: `LAST-MODIFIED` is the property clients use
  for this, and the repository has no counter to derive a sequence from.

Encoding rules that are easy to get wrong and are therefore binding:
CRLF line endings, content lines folded at 75 octets with a leading space on
continuations, and `,` `;` `\` and newlines escaped inside every TEXT value.

### 6.2 Public download

`GET /events/{event:slug}/calendar.ics`, public, no authentication, served
as `text/calendar; charset=utf-8` with a filename from the event slug. An
event that is not publicly visible answers 404, matching the landing page.

### 6.3 The mail attachment

`AttachmentData` today names a disk and a path, deliberately, so the queued
payload stays small. A calendar file is derived, immutable for a given
event state, and about half a kilobyte; writing it to a disk would buy a
cache-invalidation problem for nothing.

`AttachmentData` therefore gains an **inline form**. The constructor becomes
`(string $as, ?string $disk = null, ?string $path = null, ?string $content = null, ?string $mime = null)`:
`as` leads because it is the only field both forms need, and every call site
uses named arguments, which Commerce's existing one already does. Exactly
one of the disk form and the inline form must be set; the constructor
refuses both and neither. `TemplatedMail::attachments()` builds
`Attachment::fromData(fn (): string => $content, $as)` for the inline form
and keeps `fromStorageDisk` for the other. Inline content over **64 KiB** is
refused, so the disk form stays the only way to attach a document and nobody
grows a queued payload by attaching a PDF.

`MailOnRegistrationConfirmed` attaches the event's calendar file to the
confirmation mail. Nothing else changes about that mail.

---

## 7. Where the participant sees this

- **Ticket link** — a slot contribution on `registration.mine.show`,
  registered by Admission, the same mechanism the seat picker uses. The
  card names how many tickets the file holds and links to the buyer's
  download. It is absent when the registration is unconfirmed or granted no
  admissions.
- **My tickets** — a participant nav entry and page listing the admissions
  whose holder is the signed-in user, each with its own download.
- **Calendar button** — a slot contribution on `event.landing`, registered
  by Event, linking to the public calendar route. The landing page has no
  action button today, so it takes its styling from the seat picker's link.

All strings are English, matching every other document and page in the
repository. There is no `lang/` directory and no translation files.

---

## 8. Module boundaries

Admission owns the ticket: `docs/architecture/module-map.md` gives it the
badge, check-in and ticket tokens, and the inventory maps LanCore's ticket
PDF and watermark to it. Event owns the calendar file, because an event's
time and venue are its data. Platform owns the watermark service and the
slot registry. Messaging owns attachments.

The calendar file is generated by Event and handed to Registration's mail
path as bytes, not as a model.

---

## 8a. The decision record this owes

**ADR-0061** records three things the repository would otherwise have to
re-derive: that a participant ticket is a rendered-on-demand Admission
document carrying the existing KVX1 token rather than a new credential; that
an admission's token is stable and a superseded one is refused, which changes
a security property and retires the docblocks in §2.1; and that a small
derived file may be attached to a mail inline, which narrows a contract
`AttachmentData` deliberately drew the other way.

---

## 9. Observability (ADR-0059)

- `admission_tickets_rendered_total{brand}` — counter, incremented per
  rendered page.
- `event_calendar_downloads_total{brand}` — counter.
- `admission_tokens_superseded_total{brand}` — counter, incremented when a
  scan is refused for a superseded nonce. This is the number that tells an
  operator whether the new enforcement is rejecting real people.
- One dashboard row on `konventix-sale` holding all three.
- **No alert.** Nothing here fails silently: a render failure is a 500 and a
  refused scan is already visible at the door. `ObservabilityCoverageTest`
  checks the metric and the panel, which is what this section owes.

---

## 10. GDPR

The ticket is never stored, so there is nothing to export or erase beyond
what Admission already handles. The forensic watermark repeats the holder's
name inside a file the holder themselves downloaded; it creates no new
record. `token_nonce_hash` is not personal data.

---

## 11. Testing

Following `BadgeDocumentTest` and `InvoiceDocumentTest`, a rendered PDF is
asserted on its magic bytes and its length, never byte-compared.

Binding cases:

- A buyer's file has one page per admission and a 403 for someone else's
  registration.
- A holder sees only their own admissions, and an admission with no linked
  account appears for nobody.
- An unconfirmed registration has no ticket and no link.
- The TESTSYSTEM layer is present off production and absent on production,
  driven through `DeploymentEnvironment`, never through `env()`.
- A brand with no logo still renders.
- **Two renders of the same ticket carry the same code**, which is the
  regression test for §5.
- `rotate()` changes the code, writes a delta entry, and the previous token
  is then refused with `NonceSuperseded`.
- An admission with no stored hash accepts a token minted before the deploy.
- The calendar file parses: CRLF endings, folded lines, escaped text,
  `DTSTART` in UTC, a stable `UID` across two requests.
- An event with no venue still produces a valid file.
- The confirmation mail carries exactly one attachment, named for the event,
  with `text/calendar`.
- `AttachmentData` refuses both forms at once, refuses neither, and refuses
  inline content over the ceiling.

---

## 12. Out of scope

- Wallet passes (#128).
- Self-service token rotation by the holder (LanCore TKT-F-024). The
  machinery is built by §5; only the participant-facing button is missing.
- An editable ticket template. The badge has one and its editor is #23; a
  ticket keeps a fixed layout for this round.
- Printing the full purchase conditions on the ticket.
- Naming the seat on the ticket. A seat claim belongs to a **registration**
  and may cover several places at once, while a ticket page belongs to one
  **admission**; which place is which person's is not modelled anywhere, so
  printing one would be a guess. Closing that needs a per-admission
  placement, which is a seating decision, not a ticket one.
- A calendar entry per event day or per session. The agenda module does not
  exist.
- Adopting the watermark service in the badge and the invoice. The service
  is built so they can, later.

---

## 13. Amendments

Deviations found during implementation are appended here with a date and the
task that found them. The body above is never edited.

- **2026-09-11, planning: the calendar builder moves to Platform, and two
  modules gain an event read model.** §8 gave the calendar file to Event.
  That works for the public route, where Event reads its own models, but not
  for the confirmation mail: Registration sends it and may not import Event
  (ADR-0004). Registration's own `registration_event_summaries` carries a
  name, a slug and visibility, and no time or venue at all; Admission has no
  event summary whatsoever, yet the ticket's event band needs a name, a
  start, an end, a timezone and a venue. So: the RFC 5545 encoder becomes
  `Konventix\Platform\Calendar\CalendarFile`, taking scalars and returning
  bytes, which every module may call the way they all call `MediaLibrary`.
  `registration_event_summaries` gains `starts_at`, `ends_at`, `timezone` and
  `venue_label`, projected by extending the existing `ProjectEventSummary`.
  Admission gains its own `admission_event_summaries` with the same columns
  and its own projector, the way Resources keeps `resource_event_windows`.
  Both project from Event's existing contract events, which already carry
  everything needed: `EventCreated` and `EventRescheduled` carry the times
  and the timezone, and `VenueAssigned`, `VenueUpdated` and `VenueUnassigned`
  carry the venue's name and city. `EventDetailsUpdated` carries no times, so
  a projector must patch the name and slug there rather than replace the row.
- **2026-09-11, planning: a registration-derived admission is anonymous
  today, which changes who can fetch a ticket.**
  `IssueAdmissionsForRegistration` hard-sets `holder_user_id` to null and
  drops the `registrantUserId` its triggering event carries, so an admission
  born from a sale names nobody at all. A person becomes the holder later,
  through the existing admission claim links. Three consequences. The
  holder's own list in §4.1 serves only claimed admissions, which is correct
  and worth saying out loud. `admissions` gains a nullable `buyer_user_id`,
  threaded from `RegistrationConfirmed` and `RegistrationAmended`, which
  already carry it. And rows that exist before this deploy have neither, so
  Registration gains a narrow `RegistrationOwnershipAnnounced` event plus a
  `registration:announce-ownership` command that emits it for every
  registration. That event exists precisely so the backfill is replay-safe:
  re-emitting `RegistrationConfirmed` would re-send every confirmation mail,
  and nothing but a projection may ever listen to the new one.
- **2026-09-11, planning: the holder-name fallback drops the billing
  recipient.** §4.2 named the registration's billing recipient as the middle
  fallback. Admission cannot read that, and no event it can reach carries a
  person's name at all. Admission therefore gains its own people read model
  on Identity's `UserRegistered` and `UserProfileUpdated`, mirroring
  Registration's `ProjectRegistrant` and Messaging's `ProjectRecipient`, and
  backfilled by the existing `identity:replay-profiles`. The chain becomes:
  the linked holder's display name, else "Unassigned", which is what the
  badge already renders. `IssueBadge`'s manually typed holder name is an
  operator affordance and stays untouched.
- **2026-09-11, planning: `event:replay-details` gains a venue pass.** It
  re-fires `EventRescheduled` and `EventDetailsUpdated`, which between them
  carry a name, a slug, the times and the timezone, but no venue. A
  backfilled event summary would therefore have no venue and the ticket band
  and calendar `LOCATION` would be silently empty. The command also re-fires
  `VenueAssigned` for each event's primary venue. An event with no venue
  renders without one, which is already required by §11.
- **2026-09-11, pre-flight scan: the unconfirmed-registration refusal in §4.1
  is structural, not a check.** A download route cannot ask whether a
  registration is confirmed without reading Registration's table, which
  ADR-0004 forbids. It does not need to: an admission row only ever comes
  into existence from `RegistrationConfirmed` or `RegistrationAmended`, so no
  admission can exist for an unconfirmed registration. The invariant is
  therefore pinned by a test asserting that `IssueAdmissionsForRegistration`
  is wired to those two events and nothing else, so a future issuance path
  added elsewhere fails that test instead of quietly handing out tickets.
- **2026-09-11, planning: the calendar file names the venue as name and
  city.** `VenueAssigned` carries no street address, so `LOCATION` and the
  ticket's event band say what the contract offers. Widening that payload is
  an Event change this issue does not need.
