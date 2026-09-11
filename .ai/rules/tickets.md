---
paths:
  - 'app-modules/admission/src/Documents/**'
  - 'app-modules/admission/src/Actions/IssueAdmissionToken.php'
  - 'app-modules/admission/src/Actions/VerifyAdmissionToken.php'
  - 'app-modules/admission/src/Http/Controllers/TicketDownloadController.php'
  - 'app-modules/event/src/Http/Controllers/EventLandingController.php'
  - 'app-modules/platform/src/Documents/**'
  - 'app-modules/platform/src/Calendar/**'
  - 'app-modules/messaging/src/Data/AttachmentData.php'
---

# Tickets and calendar files

## `current()` for a read, `rotate()` for a write — `handle()` no longer exists
Calling `rotate()` where `current()` was meant silently issues a *different*
code and refuses the one already printed the next time it is scanned. Every
read path — a ticket render, a badge print, anything that only needs "the
code that is currently valid" — calls `current()`. Only a deliberate reissue
(a lost ticket, a compromised code) calls `rotate()`, and it always writes a
`token_superseded` delta entry when a previous hash existed to supersede —
there is no quiet way to rotate.

## A null `token_nonce_hash` is not a bug, it is the migration path
An admission with no stored hash accepts any otherwise-valid token — every
row minted before ADR-0061 shipped, and every admission an offline device
has never synced. Do not "close" this by refusing a null hash: that would
403 every admission that predates the deploy until it happens to be scanned
once online first. `VerifyAdmissionToken`'s comparison is opt-in per row by
design.

## `buyer_user_id` and `holder_user_id` answer different questions
A registration-derived admission is born with a buyer (whoever paid) and no
holder (nobody named yet); it stays holder-less until an
`AdmissionClaimLink` is claimed. `/my/tickets` — the holder's own list — is
legitimately empty for an unclaimed admission; that is correct, not a
regression. The buyer's own `/my/registrations/{registration}/ticket`
always lists every admission the registration granted regardless of holder.

## Two backfills close two different gaps, and neither replaces a domain event
`registration:announce-ownership` backfills `admissions.buyer_user_id` by
emitting `RegistrationOwnershipAnnounced` — a narrow event only a projection
may ever listen to, never `RegistrationConfirmed` itself, because replaying
that would re-send a confirmation mail for every registration that ever
existed. `event:replay-details` backfills the *event summary* read models
(`admission_event_summaries`, `registration_event_summaries`) and, since
this feature, also re-fires `VenueAssigned` for each event's primary venue
— rerun it after backfilling a venue onto an event created before the two
summaries existed; it does not run itself.

## `AttachmentData`'s two forms are exclusive, and the 64 KiB ceiling is on the Data object
Passing both `path` and `content` (or neither) throws at construction, not
at send time — a bad call site fails the request or job that built the
attachment, before any mailer runs. The ceiling is enforced by
`AttachmentData` itself. There is no path around the ceiling except the disk
form the ceiling exists to keep mandatory for anything that size. The row
`SendMessage::log()` writes stores the whole shape it was handed, inline
`content` included — `Actions\ResendMessage` still only ever rebuilds the
disk form, so an inline attachment is dropped, not resent, until that gap
closes (Messaging's own TODO.md).

## The environment fact is `DeploymentEnvironment::isProduction()`, never `env('APP_ENV')`
`DocumentWatermark::testSystemBanner()` reads it, and `CalendarFile` neither
knows nor cares about environment at all. A ticket rendered on an instance
whose `APP_ENV` was never set shows TESTSYSTEM by design (ADR-0053's
default is `undeclared`, not `production`) — that is the ribbon's own rule
working as intended, not a ticket-rendering bug.

## `CalendarFile` emits UTC instants and no `VTIMEZONE`, on purpose
Do not "improve" this by adding `TZID`: a correct `VTIMEZONE` block needs
full daylight-saving rules the codebase has no source for, and a UTC
instant already renders correctly in every client's own timezone (design
§6.1). `admission_event_summaries` and `registration_event_summaries` are
two separate projections of the same Event facts, not one shared table —
extending one's columns does not extend the other's.

## `OperatorDateTimeFormat::dateTime($at, $zone)` converts, it does not label
Passing an event's own timezone to it for `starts_at`/`ends_at` shifts a
wall-clock value that already carries no offset (ADR-0027) — those columns
are the local time as written, not a UTC instant waiting for a zone. Every
ticket printed the event two hours late this way until it was caught by
actually rendering one. Print an event's own wall-clock fields as stored;
reach for `OperatorDateTimeFormat` only when converting a real instant
(`startsAtInstant()`, a timestamp column) into an operator's own local
display.

## The watermark paints before the content, not after
`DocumentWatermark`'s layers must render **before** the ticket's content
bands in the page HTML, so the holder band's opaque QR box — painted after,
in document order — covers whatever the watermark drew underneath it.
Getting this order backwards silently prints TESTSYSTEM (and the forensic
repeat) on top of the QR, breaking the scan. A structural test pins the
rendered Blade HTML's layer order for exactly this reason; if it starts
failing, the fix is to restore the order, never to relax the test.

## Tests
A rendered PDF is asserted on its magic bytes and its length, never
byte-compared, following `BadgeDocumentTest`/`InvoiceDocumentTest`. The
calendar file is asserted by parsing it — CRLF endings, folded lines,
escaped text — never by string-matching the raw bytes.

## Observability
No alert exists for anything in this feature (ADR-0059, design §9): a
render or download failure is a plain 500 already covered by
`KonventixHttp5xxRate`/`KonventixErrorsLogged`, and a superseded-nonce
refusal is visible to the person at the door. `admission_tickets_rendered_
total`, `event_calendar_downloads_total` and `admission_tokens_superseded_
total` all live on the `konventix-sale` dashboard's "Tickets and calendars"
row — adding a fourth metric here without a panel fails
`ObservabilityCoverageTest`.
