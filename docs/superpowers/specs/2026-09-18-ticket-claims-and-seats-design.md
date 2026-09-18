# Ticket claims, the ticket page and a seat per ticket — design

> Written 2026-09-18. Closes the participant half of #16 (claim links from
> the UI, holder assignment) and reopens what the seating design parked in
> its §14: a seat bound to an admission rather than to a registration.
> Deliberately narrower than #16's organizer console, which follows on the
> same Actions.

## 1. What this builds

Three things a person sees, resting on one modelling change:

1. **A ticket manager panel** on the registrant's own registration page:
   one row per admission the registration granted, naming who holds it, with
   the actions *claim for myself*, *invite by e-mail*, *copy invite link*,
   *revoke invite* and *take back*. The buyer of a registration is its
   admissions' **manager** (`admissions.buyer_user_id`); whoever is named on
   an admission is its **user** (`admissions.holder_user_id`). The two
   columns exist; the vocabulary and the panel do not.
2. **A ticket page** per admission, `/my/tickets/{admission}`: event band,
   holder, role, validity, seat, the QR of the admission's current KVX1
   token on screen (ADR-0061: the same credential the PDF carries, nothing
   new minted), the PDF download, the calendar link — and the way into the
   seat picker. The manager and the holder both reach it.
3. **A seat per ticket.** The picker moves from the registration to the
   admission: `resource_bookings` gains `admission_id`, the seat claim's
   `quantity` stays the group's cap, and each admission binds at most one
   place. The manager may seat every admission of the registration, the
   holder their own; the last write wins.

And the invitee's side of a claim link: a landing page that says what is
being offered before the existing POST accepts it.

4. **An event banner.** An event gains one picture from the brand's media
   library; the ticket page shows it as the event band's backdrop and the
   PDF prints it across the top of the event band. It reaches Admission the
   way every other event fact does: through Event's events into
   `admission_event_summaries`.

## 2. What already exists (verified 2026-09-18)

- `admissions` carries `registration_id`, `amendment_id`, `event_id`,
  `holder_user_id` (nullable), `buyer_user_id` (nullable), `role`,
  `valid_from`, `valid_until`, `state` (`issued`, `assigned`, `revoked`,
  `expired`), `token_nonce_hash`, `token_issued_at`.
  `IssueAdmissionsForRegistration` creates every registration-derived
  admission holder-less with the buyer set.
- `AdmissionClaimLink` (`admission_id`, `intended_email`, `expires_at`,
  `claimed_by_user_id`, `claimed_at`, `revoked_at`) with
  `IssueAdmissionClaimLink::handle(Admission, ?string $intendedEmail)`
  (168 h lifetime, re-issue supersedes, partial unique index on the live
  link) and `urlFor()` building a `temporarySignedRoute('admission.claim')`
  whose expiry **is** the row's. `ClaimAdmission` burns the link under a
  row lock, refuses a bound link from the wrong address, sets the holder and
  `AdmissionState::Assigned`, and emits `AdmissionHolderAssigned`
  (`admissionId, registrationId, eventId, brandId, holderUserId,
  viaClaimLink, assignedAt`). The only route is
  `POST /admissions/claim/{link}` (`signed` + `auth`), answering every
  failure as one 410. Nothing issues, sends, shows or revokes a link from
  any UI; `Permission::AssignHolder` and `ReassignHolder` exist and are
  wired to nothing.
- `AdmissionIssued` carries `admissionId, registrationId, eventId, brandId,
  role, holderUserId, issuedAt` — **no buyer**. `AdmissionRevoked` carries
  `admissionId, registrationId, eventId, brandId, revokedByUserId,
  reasonCode, revokedAt`. Check-in is a `CheckIn` row per admission;
  `RevokeAdmissionsForRegistration` already reads it to spare a checked-in
  admission.
- Participant surfaces: `GET /my/tickets` (`MyTicketsController`, holder's
  list, `MyTicketData {id, eventId, eventName, role, state}`, a row is a
  PDF download), `GET /my/admissions/{admission}/ticket` (holder's PDF),
  `GET /my/registrations/{registration}/ticket` (buyer's combined PDF,
  authorised on `buyer_user_id`), and Admission's
  `registration.mine.show` slot contribution `TicketLinkSlot.vue`
  (`TicketLinkData {registrationId, ticketCount}`, order 30). The
  registration page passes the slot context `['registrationId' => …]`
  only; `MyRegistrationsController::show` has already proven ownership.
- Read models Admission keeps: `admission_event_summaries` (`EventSummary`:
  name, slug, starts/ends, timezone, venue label) and `admission_people`
  (`Person`: `id`, `display_name`, `email`) projected from Identity.
- `TicketDocumentRenderer` builds the page from
  `IssueAdmissionToken::current()` and a private `qrDataUri()`
  (`bacon/bacon-qr-code`, SVG data URI). The ticket names no seat; the
  tickets design §12 listed exactly this as out of scope because no
  per-admission placement exists.
- Seating (Resources): `resource_claims` (`event_id`, `registration_id`,
  `registrant_user_id`, `amendment_id`, `resource_type_ids`,
  `seating_mode` pick|assign, `quantity`, `state` unplaced|placed|released);
  `resource_bookings` (`resource_id`, `holder_type` registration|operator,
  `holder_id`, `registration_id`, `claim_id`, `amendment_id`, `quantity`,
  `label`, `is_exclusive`, `state`, `starts_at`, `ends_at`,
  `hold_expires_at`) under the exclusion constraint
  `resource_bookings_no_overlap`. `AssignPlacesToRegistration::handle(
  ResourceClaim, list<string> $placeIds, $actorType, ?$actorUserId)` is the
  one pick path: locks the claim, releases its live bookings (`repicked`),
  hold+confirm per place, actor `registrant` must equal
  `claim.registrant_user_id`, actor `operator` needs
  `Permission::BookOnBehalf`. `FindSeatClaimForRegistrant` answers null for
  anybody but the registrant. `SeatPickController` serves
  `GET|POST /my/registrations/{registration}/seat` and
  `SeatAvailabilityController` the 15-second availability poll; `Pick.vue`
  with `SeatPlanScene.vue` and the list view; `SeatSummarySlot.vue` on
  `registration.mine.show` shows `SeatSummaryData {registrationId,
  claimState, quantity, placeRefs, noFreePlaces, seatingMode}`.
- `ResourceBookingConfirmed` / `ResourceBookingReleased` carry `bookingId,
  resourceId, brandId, holderType, holderId, registrationId, claimId,
  eventId, resourceTypeId, label, ref` plus their timestamps — everything a
  foreign read model needs except which admission sits there.
- Messaging: `SendMessage::handle(SendMessageRequest)` takes either a
  `recipientId` or a `recipientEmail`; templates are `MessageTemplate`
  objects registered in a module's `registerMessageTemplates()` with
  Blade bodies per locale (`registration.waitlist-offer` is the precedent),
  payload a `Data` class.
- Event serves the public calendar file at
  `GET /events/{event:slug}/calendar.ics`; `admission_event_summaries`
  carries the slug.
- **An event has no image of any kind.** `events` carries name, slug,
  description, times, timezone, state, visibility, re-entry policy and the
  lifecycle timestamps; `EventCreated` / `EventDetailsUpdated` carry no
  media id. Media is Platform's library (ADR-0035): a model stores a media
  ULID, `MediaLibrary::urlFor($id, $variant)` gives the public URL,
  `dataUriFor()` the inline form dompdf needs (the brand logo on the
  ticket already uses it), `recordUsage()` / `releaseUsage()` track who
  wears a picture (`UpdateBrand::syncMediaUsage()` is the shape), and
  `MediaPicker.vue` is the admin form control (`admin/brands/Edit.vue`,
  `admin/products/Edit.vue`, the hero block editor). The picker only
  *chooses*: it takes the candidate list as a prop
  (`MediaLibrary::pickableImages($brandId)`, `MediaItemData`) and has no
  way to add to it — uploading means leaving the form for `/admin/media`
  (`MediaAdminController::store`, `Permission::ManageMedia`, `brand_id` +
  `file` ≤ 10 MiB in `jpg,jpeg,png,gif,webp,pdf`, no SVG by #252, then a
  redirect to the library index). `StoreMediaUpload` is the Action; the
  `thumb` variant is generated afterwards (`variantsPending`).
- **Delivery from object storage already exists.** `config/filesystems.php`
  has `media` (local, served off the `public/storage` symlink) and
  `media_s3` (ADR-0043: one public bucket, `public_direct` decides whether
  the URL is the bucket's or the application's own media route);
  `MediaLibrary::urlFor()` hides the difference from every consumer. The
  dev cluster serves media from Garage at `s3.dev.konventix.de` this way.
  Nothing new is needed for "serve the banner from S3".
- Every mail wears `messaging::layouts.branded` (ADR-0048): a 600 px table
  with an accent-coloured header row holding the brand logo, the body, and
  a footer. `MailLayout` builds it from `BrandMailIdentityData`; templates
  supply only the body. `SendMessageRequest` carries `recipientId`,
  `templateKey`, `payload`, `recipientEmail`, `recipientLocale`, `brandId`,
  `attachments`, `topicKey`, `urgent`. `registration_event_summaries`
  carries name, slug, visibility, times, timezone and venue label — the
  confirmation mail's `eventName` comes from it.

## 3. Vocabulary

| Word in the UI | Column | Who |
|---|---|---|
| Manager | `admissions.buyer_user_id` | whoever the registration belongs to; may name, invite, take back and seat every admission of it |
| Holder | `admissions.holder_user_id` | the person who walks in; sees and downloads their own ticket, may move their own seat |

The organizer's desk path (#16 point 3, #73 "Umschreibung") uses the same
Actions with `Permission::AssignHolder` / `ReassignHolder` instead of
ownership; only its console screen is out of scope here.

## 4. Admission: holders

### 4.1 Actions

- `AssignHolder::handle(Admission $admission, string $holderUserId, string $actorType, ?string $actorUserId): Admission`
  — in one transaction, lock the row; refuse unless `holder_user_id` is
  null and state is `issued`/`assigned` (same guard as `ClaimAdmission`);
  revoke any live claim link (a named admission has nothing left to
  claim); set holder and `Assigned`; emit `AdmissionHolderAssigned` with
  `viaClaimLink: false`. Actor `manager` requires
  `admission.buyer_user_id === $actorUserId`; actor `operator` requires
  `Permission::AssignHolder` through Platform's `PermissionHolders` (the
  way `AssignPlacesToRegistration` checks `BookOnBehalf`, no User import).
  The manager's only legitimate `$holderUserId` is their own id — "this
  one is mine" — and the controller passes nothing else; naming a third
  person without their consent is what the invite exists for.
- `UnassignHolder::handle(Admission $admission, string $actorType, ?string $actorUserId, string $reasonCode): Admission`
  — lock; refuse when a `CheckIn` row exists for the admission (a person
  who is already inside cannot be un-named; #73's rule); refuse a revoked
  admission; null the holder, state back to `Issued`; emit **new**
  `AdmissionHolderUnassigned(admissionId, registrationId, eventId, brandId,
  previousHolderUserId, unassignedByUserId, reasonCode, unassignedAt)`.
  Actor `manager` on `buyer_user_id`; actor `operator` on
  `Permission::ReassignHolder`. Reassignment is unassign followed by
  assign or invite — two events, never a silent swap, so the audit reads
  what happened.
- `IssueAdmissionClaimLink` stays as is. New `SendClaimLink::handle(
  AdmissionClaimLink $link, string $inviterUserId): void` renders the
  `admission.claim-link` template to `intended_email` through
  `SendMessage` with `recipientId: null, recipientEmail: $link->intended_email`
  (a stranger has no account yet — that is the point). Failure is logged
  the way `RegistrationMailer::send()` does and never rolls the link back:
  the link exists, the manager can still copy it.
- `RevokeClaimLink::handle(AdmissionClaimLink $link, string $actorType, ?string $actorUserId): void`
  — sets `revoked_at` on a live link; idempotent on a dead one.

### 4.2 Events

`AdmissionIssued` gains `buyerUserId: ?string` (additive; every producer
sets it, `IssueCrewAdmission` passes null). `AdmissionHolderUnassigned` is
new. `AdmissionHolderAssigned` is unchanged.

For backfilling Resources' read model (§6.1) a narrow
`AdmissionOwnershipAnnounced(admissionId, registrationId, eventId, brandId,
holderUserId, buyerUserId, state)` plus `admission:announce-ownership`,
mirroring `registration:announce-ownership` and its rule: nothing but a
projection may ever listen to it, because re-emitting `AdmissionIssued`
would re-run the metrics and delta-feed listeners for every admission that
ever existed.

### 4.3 Routes (brand host, `web` + `auth`, `routes/web/admission.php`)

| Route | Name | Does |
|---|---|---|
| `POST /my/admissions/{admission}/holder` | `admission.holder.store` | manager claims for themselves (`AssignHolder`, actor `manager`, holder = auth id) |
| `DELETE /my/admissions/{admission}/holder` | `admission.holder.destroy` | manager takes back (`UnassignHolder`, reason `manager_unassigned`) |
| `POST /my/admissions/{admission}/invite` | `admission.invite.store` | `IssueAdmissionClaimLink` with the posted `email` (nullable → an unbound, copyable link), then `SendClaimLink` when an address was given; responds with the signed URL in the flash so the panel can show "copy" |
| `DELETE /my/admissions/{admission}/invite` | `admission.invite.destroy` | `RevokeClaimLink` |
| `GET /admissions/claim/{link}` | `admission.claim.show` | the invitee's landing page (`signed`); a dead link renders the 410 page, an unauthenticated visitor is sent to login with the intended URL kept |
| `GET /my/tickets/{admission}` | `admission.tickets.show` | the ticket page, for holder or manager |

Authorisation is inline on `buyer_user_id` / `holder_user_id`, never a
Registration table (ADR-0004), exactly as `TicketDownloadController` does
it; a stranger gets 404, not 403, so the URL confirms nothing. The
existing `POST /admissions/claim/{link}` keeps its behaviour; after
success it redirects to the new ticket page instead of `back()`.

Rate limit: the two invite routes `throttle:20,1` — an invite sends mail.

### 4.4 Data

- `TicketManagerData {registrationId, ticketCount, admissions: list<ManagedAdmissionData>}`
  replaces `TicketLinkData` on the `registration.mine.show` slot.
  `ManagedAdmissionData {id, role, state, holderUserId, holderName,
  isSelf, checkedIn, invite: ?InviteData, seat: ?TicketSeatData}` where
  `InviteData {intendedEmail, expiresAt, url}` — the URL is rebuilt by
  `IssueAdmissionClaimLink::urlFor()` on every render, never stored.
- `TicketPageData {id, eventId, eventName, eventSlug, bannerUrl: ?string,
  eventStartsAt, eventEndsAt, eventTimezone, venueLabel, role, state, validFrom,
  validUntil, holderName, isHolder, isManager, checkedIn, qrSvg, doorCode,
  seat: ?TicketSeatData, canPickSeat, pickSeatUrl}` — `qrSvg` is the SVG
  markup (not a data URI; it is inlined into the DOM), produced by a new
  `Documents\TicketQr` service that `TicketDocumentRenderer` also uses in
  place of its private helper.
- `TicketSeatData {bookingId, placeRef, placeLabel, planName}` from the
  read model in §5.
- `ClaimLandingData {linkId, eventName, role, holderName: null,
  intendedEmail: ?string, expiresAt, managerName, alreadyHolderOfAnother: bool}`.
- `InviteData` for the form: `email: ?string` (validated `email:rfc,dns`
  when present).

### 4.5 Pages and components

- `admission/tickets/Show.vue` — the ticket page. Card layout in the
  brand's tokens: event band (name, when, where), a holder band with the
  QR in an opaque box and the door code under it, the seat chip or the
  "choose your seat" button, then actions: download PDF, add to calendar
  (`/events/{slug}/calendar.ics`), and for a manager the panel's row
  actions for this one admission. The QR is `v-html` of `qrSvg` inside a
  fixed-size box so it never scales below scannable; `role="img"` with an
  `aria-label` carrying the door code.
- `admission/tickets/Index.vue` — rows link to the ticket page rather than
  straight to the PDF, and gain a "managed by you" section listing the
  admissions the person bought but does not hold, so a manager who holds
  none of their group's tickets still has an entry point.
- `admission/claim/Show.vue` — the landing page: what event, what role,
  who invites, when the offer closes, one button that posts the existing
  claim route. If the signed-in person already holds another admission of
  the same event it says so (they can still claim; two tickets for one
  person is a manager's decision, not the system's).
- `admission/TicketManagerSlot.vue` replaces `TicketLinkSlot.vue`: heading
  "Tickets", the download-all button, then one row per admission:
  role, holder name or "Not yet assigned", seat chip, and the actions the
  row's state allows — *This one is mine* (unassigned, and the manager
  holds none of this registration yet — a manager who already holds one
  sees it as a secondary action, not hidden), *Invite* (opens an inline
  form: e-mail optional; submit yields either "sent to x@y" or the copy
  field), *Copy link* / *Revoke* when a live invite exists, *Take back*
  when assigned and not checked in, *Ticket* linking to the ticket page.
  The stateful invite form takes ADR-0038's unsaved-changes guard.

### 4.6 Mail

`admission.claim-link`, registered in `AdmissionServiceProvider::
registerMessageTemplates()`; payload `ClaimLinkMailData {eventName,
managerName, role, claimUrl, expiresAt}`; de and en bodies in the
`registration.waitlist-offer` shape. Sent only for a bound link.

## 5. Admission: the seat on the ticket

A read model `admission_seats` (`admission_id` PK, `brand_id`,
`booking_id`, `place_ref`, `place_label`, `resource_type_id`) projected by
`ProjectAdmissionSeat` from `ResourceBookingConfirmed` (upsert when
`admissionId` is non-null) and `ResourceBookingReleased` (delete by
`booking_id`). Both events gain `admissionId: ?string` (§6.2). Ungated,
like every read-model projector.

With that, the ticket PDF's holder band names the seat — the reason the
tickets design left it out (§12: "which place is which person's is not
modelled anywhere") no longer holds. `TicketDocumentRenderer` reads the
read model, never Resources' tables.

## 5a. Event: the banner

- `events.banner_media_id` — nullable ULID into Platform's media library,
  no FK (ADR-0035). One picture, landscape; the picker's own validation
  says which formats the library takes. No dark variant: a banner is a
  photograph or artwork, not a logo.
- `UpdateEventDetails` (and `CreateEvent`) take `bannerMediaId: ?string`
  on their Data, verify `MediaLibrary::belongsToBrand()`, and sync usage
  with `recordUsage()` / `releaseUsage()` under `('event', 'event', $id)`
  exactly as `UpdateBrand` does — so the library refuses to delete a
  picture an event still wears. The event editor gets a `MediaPicker`
  under the description.
- `EventCreated` and `EventDetailsUpdated` gain `bannerMediaId: ?string`
  (additive). **Both** event summaries gain `banner_media_id` —
  `admission_event_summaries` for the ticket, `registration_event_summaries`
  for the mails — each patched by its own projector on
  `EventDetailsUpdated` the way name and slug are (`.ai/rules/tickets.md`:
  two projections, two columns). `event:replay-details` already re-fires
  `EventDetailsUpdated`, so existing events backfill with the command that
  exists.
- The ticket page shows `bannerUrl` (`MediaLibrary::urlFor()`, a
  banner-sized variant if the library has one, else the original) as the
  event band's backdrop with a gradient scrim so the event name stays
  legible in both themes; without a banner the band is the brand's accent
  surface, as the page would look today. The PDF's event band gets the
  banner as a full-width image above the text through `dataUriFor()`,
  and the watermark still paints underneath it (`.ai/rules/tickets.md`:
  layers before content). Rendering degrades to no image when the media id
  no longer resolves — a deleted picture must never cost a ticket.
- **The public event landing page** shows the banner as a hero above the
  blocks: Event reads its own row and resolves the URL through
  `MediaLibrary::urlFor()`; the `<img>` carries the media item's alt text
  or the event name.
- **Mails** get a hero row. `SendMessageRequest` gains `heroImageUrl:
  ?string` and `heroImageAlt: ?string`; `MailLayout` hands them to
  `branded.blade.php`, which renders one full-width `<img>` row between
  the header and the body when a URL is present — one layout change, no
  `<img>` in any template body. `RegistrationMailer::send()` sets it from
  `registration_event_summaries.banner_media_id` for every lifecycle mail
  it sends (confirmed, approved, cancelled, …); `SendClaimLink` sets it
  from `admission_event_summaries`. The URL must be absolute and publicly
  reachable, which `urlFor()` already guarantees for both delivery modes;
  a mail client that blocks remote images shows the alt text and loses
  nothing.

## 5b. Platform: upload without leaving the form

`MediaPicker.vue` gains an **Upload** action beside *Choose* on every form
that already uses it (brand, product, hero block, and the event editor
from §5a):

- A file input inside the picker's dialog (same `mimes` and size rule the
  library enforces; the client checks `accept` and size before sending so
  a 12 MiB drop fails with a sentence, not a 422 after the upload) and an
  optional alt text.
- The upload goes to a **new** `POST /admin/media/quick` (`admin.media.
  quick-store`, `Permission::ManageMedia`, same validation as `store`,
  same `StoreMediaUpload` Action) that answers JSON `MediaItemData` instead
  of redirecting. The picker sends it with Inertia v3's `useHttp`, then
  prepends the item to its local candidate list and selects it — the
  form's `modelValue` becomes the new ULID without a page round-trip.
  `variantsPending` is true at that moment, so the tile shows `url` until
  a thumb exists, which the picker already handles.
- The picker needs the brand: a new required `brandId` prop; every
  consumer already knows it (the brand page edits one, the product and
  the event belong to one, the hero editor receives it from its page).
- Who may: anyone with `ManageMedia`, which is the operator's own
  capability. A user who may edit the event but not the library sees
  *Choose* only — the button is hidden when the shared Inertia prop
  `can.manageMedia` is false, and the route refuses regardless.
- `admin/media/Index.vue` is untouched; the quick route is the same
  Action behind a second door.

## 6. Resources: a seat per admission

### 6.1 `resource_admissions` — new read model

| Column | Note |
|---|---|
| `id` | the admission ULID (PK, no FK) |
| `brand_id` | |
| `registration_id` | nullable — staff admissions have none and never seat this way |
| `event_id` | |
| `holder_user_id` | nullable |
| `buyer_user_id` | nullable |
| `state` | `issued`, `assigned`, `revoked` |

Projected by `ProjectAdmission` from `AdmissionIssued` (needs the new
`buyerUserId`), `AdmissionHolderAssigned`, `AdmissionHolderUnassigned`,
`AdmissionRevoked`, and `AdmissionOwnershipAnnounced` (the backfill).
Ungated. Index `(brand_id, registration_id)`.

### 6.2 `resource_bookings.admission_id`

Nullable ULID, no FK, index `(brand_id, admission_id)`. A partial unique
index `resource_bookings_one_place_per_admission` on `(admission_id)` where
`admission_id IS NOT NULL AND state IN ('held', 'confirmed')` — one place
per admission is a schema fact, not an application check, matching how
`resource_bookings_no_overlap` already decides the other race.

`HoldResourceBooking` takes an optional `admissionId` and writes it;
`ResourceBookingHeld`, `ResourceBookingConfirmed`, `ResourceBookingReleased`
carry `admissionId: ?string`. Nothing else writes the column
(`.ai/rules/seating.md`).

### 6.3 `AssignPlaceToAdmission` — the new pick path

`handle(ResourceClaim $claim, ResourceAdmission $admission, ?string $placeId, string $actorType, ?string $actorUserId): ?ResourceBooking`

Inside one transaction, in the order `AssignPlacesToRegistration` set:

1. Lock the claim `FOR UPDATE`; refuse `released`; refuse
   `seating_mode = assign` for a non-operator actor.
2. Refuse unless `admission.registration_id === claim.registration_id` and
   `admission.state !== revoked`.
3. Release the admission's own live booking (`repicked`) if any.
4. `$placeId === null` → done (the seat is given up; claim stays `placed`
   while any booking of it remains, else `unplaced`).
5. **Cap**: count the claim's occupying bookings bound to *other*
   admissions plus its unbound ones; refuse when `>= quantity` with
   "your group's seat allowance is used up". Exception: when the chosen
   place is itself held by an **unbound** booking of this claim, that
   booking is released and re-held bound — picking a place your group
   already holds means "this seat is this ticket's". That is also the
   migration path for bookings made before this change.
6. Eligibility exactly as today (published plan, event of the claim,
   category in `resource_type_ids`, not deleted, not blocked).
7. `HoldResourceBooking` with `admissionId` immediately followed by
   `ConfirmResourceBooking`; `23P01` / `40P01` become the `placeIds`
   validation error naming the place; claim → `placed`.

Actors: `registrant` passes when `$actorUserId` equals
`claim.registrant_user_id` (the manager) **or** `admission.holder_user_id`
(the holder); `operator` needs `BookOnBehalf` as today. The last write
wins by construction; a holder who moves their seat after the manager
placed them is not a conflict.

`AssignPlacesToRegistration` stays for the operator CLI, the console
reseat and any registration without admissions in the read model (a
seating grant on a type with no admission grant — legal today, see §12);
its bookings stay unbound and count against the cap.

### 6.4 Picker routes and page

| Route | Name |
|---|---|
| `GET /my/admissions/{admission}/seat` | `resources.admission-seat.show` |
| `POST /my/admissions/{admission}/seat` | `resources.admission-seat.store` (`placeId: ?string`) |
| `GET /my/admissions/{admission}/seat/availability` | `resources.admission-seat.availability` |

`FindSeatClaimForAdmission::handle(string $admissionId, string $userId): ?array{claim, admission}`
answers null — 404 — unless the read-model row exists, is not revoked, and
the user is its holder or its claim's registrant. `Pick.vue` is reused
with `SeatPickerData` gaining `admissionId: ?string` and `maxPlaces`
(1 for an admission, `quantity` for a registration); the scene's
selection model becomes single-select when `maxPlaces === 1`. The
registration-level routes stay for the fallback in §6.3 but the
`SeatSummarySlot` no longer links to them when the registration has
admissions; it lists each admission's seat instead (from
`resource_bookings.admission_id`, which Resources owns) and links to the
per-admission picker.

### 6.5 Cancellation, revocation, plan conflicts

- `ReleaseBookingsOnRegistrationCancelled` / `...Rejected`: unchanged, a
  bound booking is still the registration's.
- **New** `ReleaseBookingOnAdmissionRevoked` on `AdmissionRevoked`:
  releases the admission's bound booking (`admission_revoked`), ungated for
  the same reason the other release listeners are. Today a revoked
  admission's seat stayed parked only because nothing bound them.
- `AdmissionHolderUnassigned` does **not** release the seat: the seat is
  the ticket's, the next holder inherits it. A manager who wants the seat
  freed gives it up on the ticket page.
- Plan conflicts: unchanged; a bound booking released by `plan_conflict`
  simply leaves the admission seatless and the claim `unplaced`.

## 7. Where the participant sees this

- **Registration page** (`registration.mine.show`): Admission's panel (§4.5,
  order 30) and Resources' seat summary (order as today) sit side by side;
  the seat summary's per-admission rows say "Seat A12 — Anna", "No seat yet
  — Ticket 2 (unassigned)". Both slots resolve on `registrationId` alone,
  as now.
- **My tickets** (`/my/tickets`): held tickets, then "Tickets you manage".
- **Ticket page**: everything about one admission on one screen.
- **Claim landing page**: reached only from a link.
- **Account navigation**: unchanged ("My tickets").

## 8. Module boundaries

Admission owns holders, links, the mail and the ticket page. Resources owns
the binding of a place to an admission, and learns of admissions only
through Admission's events into its own read model (ADR-0004). Admission
learns of seats only through Resources' booking events into its own read
model. Neither imports the other's model; the two read models are two
projections, not one shared table (`.ai/rules/tickets.md`). Event owns the
banner and announces it; Admission renders what its summary holds and
resolves the media id through Platform's `MediaLibrary`, never through
Event. Registration is untouched — its page already renders whatever the
two slots return. Messaging carries the mail. Platform's `PermissionHolders`
answers the operator-actor checks.

## 9. Observability (ADR-0059)

Counters through `Metrics`, labelled by brand slug and a bounded outcome:

- `admission_holder_changes_total{outcome=assigned_self|assigned_claim|assigned_desk|unassigned}`
- `admission_claim_invites_total{outcome=issued|sent|send_failed|revoked}`
- `resource_admission_seats_total{outcome=placed|given_up|refused}`

Panels on the `konventix-sale` dashboard: a "Holders and invites" row next
to "Tickets and calendars", and the new counter on the existing Seating
row. One alert: `KonventixClaimInviteMailFailing` (warning) when
`send_failed` exceeds `sent` over 30 minutes — a manager whose invites
silently never arrive is a failure nobody at the venue sees.
`ObservabilityCoverageTest` enforces the panels.

## 10. GDPR

`AdmissionGdprHandler`: export gains the admissions the subject manages
(`buyer_user_id`) and the claim links they were invited by
(`intended_email`) or claimed; erasure nulls `intended_email` and
`claimed_by_user_id` on links, as it already nulls the holder.
`ResourcesGdprHandler`: erasure nulls `holder_user_id` / `buyer_user_id` on
`resource_admissions`; export lists seats by admission where the subject
is holder or buyer. `admission_seats` carries no personal data.

## 11. Testing

Feature tests per module, factories throughout:

- Admission: manager self-claim; manager cannot name a third party; a
  holder cannot claim a second time; take back refused after check-in;
  assignment revokes a live invite; invite with e-mail sends `admission.claim-link` to that
  address and binds the link; invite without e-mail sends nothing and
  returns a copyable URL; revoke; a stranger gets 404 on every `/my/…`
  route; landing page renders for a live link, 410 for a dead one, redirects
  to login when anonymous and comes back; ticket page for holder, for
  manager, 404 for anybody else; QR SVG present and equal to the PDF's
  token; `AdmissionOwnershipAnnounced` reaches only projections (the same
  structural test the Registration counterpart has); GDPR export and
  erasure.
- Resources: read model from each admission event; bind a place to an
  admission; second place for the same admission is refused by the partial
  index (inside the Action's savepoint); cap honoured across bound and
  unbound bookings; picking a group's unbound place rebinds it; holder may
  move own seat, manager any, stranger none; `assign` mode refused; revoked
  admission releases its booking; unassigned holder keeps the seat;
  `SeatSummaryData` lists per-admission rows; `Pick.vue` single-select
  (vitest on the selection model).
- Admission's `admission_seats` from the two booking events, and the ticket
  PDF naming the seat (magic bytes and length, never byte-compared).
- Event: `banner_media_id` saved and its usage recorded, released on
  change, refused for another brand's media; `EventDetailsUpdated` carries
  it; both summaries' `banner_media_id` projected and patched, backfilled
  by `event:replay-details`; the landing page renders the hero and
  degrades without one; the ticket page's `bannerUrl` is null when the
  media id no longer resolves; the PDF renders with and without a banner
  and keeps its layer order (the existing structural test extended).
- Messaging: the layout renders the hero row only with a URL; the
  confirmation mail and the claim mail carry it; `MailLayout` snapshot
  parsed, not byte-compared.
- Platform: quick upload answers `MediaItemData` JSON, refuses without
  `ManageMedia`, refuses SVG and oversize, records the uploader; vitest on
  the picker's local-list-and-select behaviour.
- `npm run build` before any test rendering the new pages
  (`.ai/rules/seating.md`).

## 12. Decisions and flagged items

1. **Per-admission seats reopen seating design §14.** Owner's call on
   2026-09-18; recorded here rather than by editing that document.
2. **A seating grant on a type without an admission grant** keeps the
   registration-level picker. Not forbidden in the type editor this round;
   flagged for the type-editor follow-up.
3. **Quantity above the number of admissions** is unusable by participants
   (every admission has its one seat; the rest of the allowance sits idle).
   The operator can still assign it. Deliberate: the alternative — several
   seats on one ticket — is what the design just moved away from.
4. **The manager names only themselves.** Naming somebody else without a
   claim is an organizer capability, not a purchase right.
5. **Take back keeps the seat on the ticket**, because the seat was bought
   by the manager, not the holder.
6. **Last write wins between holder and manager** — no lock, no approval.
7. **An unbound link is a bearer token** for its lifetime, as
   ADR-0032 already accepted; the copy field says so in one sentence.

## 13. Out of scope

The organizer console for claim links and desk assignment (#16 points 2
and 3 — the Actions are built here, the screens are not); the
`admission.claim-link` mail to an *existing* account by user id instead of
address (an address is always known, and works for both); transferring the
manager role itself (a registration transfer, ADR-0036); wallet passes
(#128); a per-event ticket colour or template; a banner-sized media
variant (the original is served; a `banner` variant is a
`ThumbnailGenerator` follow-up); drag-and-drop or multi-file upload in the
picker; self-service token rotation; seat neighbours and group placement;
occupancy names on the plan (SET-F-009); German UI strings (#132).

## 14. Amendments

Deviations found during implementation are appended here with a date and
the task that found them. The body above is never edited.

- **2026-09-18, Task 1: no Data class for the upload.** §5b's plan named a
  `MediaQuickUploadData`; the repository has no Data class carrying an
  `UploadedFile` and the generated TypeScript would have nothing to say
  about one. `store()` and `quickStore()` share a private `upload()` on
  the controller with the validation `store()` always had. The tests live
  in `MediaLibraryTest.php` next to `mediaOperator()`, which that file
  declares and a second file may not call (`.ai/rules/tests.md`).
