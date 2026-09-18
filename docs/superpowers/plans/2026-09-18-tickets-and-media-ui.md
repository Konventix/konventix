# Tickets and media UI — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task names the precedent whose shape the new code copies; read the precedent before writing.

**Goal:** A buyer manages who holds each ticket of their registration and invites people to claim one; every ticket has a page of its own with the scan code, the seat and the event's banner; a seat belongs to a ticket rather than to a registration; an event carries a banner that reaches the ticket, the PDF, the landing page and every mail; and an operator uploads a picture from inside any form that picks one.

**Architecture:** Admission owns holders, claim links, the ticket page and the ticket document. Resources owns the binding of a place to an admission and learns of admissions through Admission's events into `resource_admissions`; Admission learns of seats through Resources' booking events into `admission_seats`. Event owns the banner and announces it; both event-summary read models gain the column. Platform's `MediaLibrary` resolves every media id, and its picker gains an upload door on the same `StoreMediaUpload` Action. Messaging's layout renders a hero image the sender names. No module imports another's model (ADR-0004).

**Tech Stack:** PHP 8.5, Laravel, Inertia v3 + Vue 3 (`useHttp` for the quick upload), spatie/laravel-data with generated TypeScript, `barryvdh/laravel-dompdf`, `bacon/bacon-qr-code` (SVG), Pest, vitest.

**Spec:** `docs/superpowers/specs/2026-09-18-ticket-claims-and-seats-design.md` — the binding contract. Section numbers below refer to it.

**Issue:** #16 for the holder work (points 1 and 3; point 2's console is not in this plan). #292 for the seat-per-ticket, banner and quick-upload parts.

## Global Constraints

- **Branch `feat/tickets-and-media-ui` off `develop`.** One merge request; never rebase a pushed history.
- **Everything PHP runs in the container**: `vendor/bin/sail …`. Host PHP is the wrong version and has no `pgsql`.
- **One test process at a time.** Wrap every test run: `flock .superpowers/sdd/2026-09-18-tickets-and-media-ui/sail.lock vendor/bin/sail artisan test --compact <path or --filter>`; the same lock wraps `vendor/bin/sail composer verify -- <modules>`. Never the full suite locally — the pipeline runs it.
- **No new composer or npm dependencies.**
- **No cross-module Eloquent imports; events carry ids and scalars** (ADR-0004). Additive payload fields only, with defaults, so every existing producer and test keeps compiling.
- **`BelongsToBrand` on every brand-owned model; `withoutBrandScope()` plus explicit `brand_id` on every console, job and listener path** (ADR-0010, ADR-0029, `.ai/rules/seating.md`).
- **ULID primary keys, no cross-module foreign keys; migrations are additive files** (ADR-0014).
- **One Data object per form or endpoint, TypeScript generated** (ADR-0009). After any Data change: `flock <lock> vendor/bin/sail composer types:generate && npx prettier --write resources/js/types`.
- **Booking rows are written only by `HoldResourceBooking`, `ConfirmResourceBooking`, `ReleaseResourceBooking`** (`.ai/rules/seating.md`).
- **`Event::listen` in the module's ServiceProvider; listeners resolve scoped bindings inside `handle()`** (`.ai/rules/listeners.md`).
- **`current()` for every token read, never `rotate()`** (`.ai/rules/tickets.md`).
- **Semantic design tokens only** (ADR-0008); Vue pages stay under ~300 lines — extract components first.
- **A PDF is asserted on magic bytes and length; mail HTML and calendar files are parsed, never byte-compared.**
- **Every new metric has a panel** (`contrib/observability/src/sale.mjs`), or `ObservabilityCoverageTest` fails (ADR-0059).
- **`vendor/bin/sail npm run build` before any test that renders a new Inertia page.**
- **Each task updates the `TODO.md` of every module it touched** and the `SPEC.md` of any module whose public contract changed (events, read models, routes).
- **Commit messages are prose, one sentence, ending `(#16)` or `(#292)`**, followed by the attribution lines your environment gives you. Stage files by explicit path.
- **Deviations from the spec are appended to its §14**, never edited into its body.

---

# Part A — Platform: upload from the picker (§5b)

### Task 1: `POST /admin/media/quick` answers JSON

**Files:**
- Modify: `app-modules/platform/routes/admin/media.php` (add the route, name `admin.media.quick-store`)
- Modify: `app-modules/platform/src/Http/Controllers/Admin/MediaAdminController.php` (add `quickStore()`)
- Create: `app-modules/platform/src/Data/MediaQuickUploadData.php` (`brandId`, `file`, `altText`; the validation currently inlined in `store()` moves here — `store()` keeps working through it too)
- Test: `app-modules/platform/tests/MediaQuickUploadTest.php`

**Interfaces:**
- Consumes: `StoreMediaUpload::handle(brandId, file, altText, uploadedByUserId)`, `MediaItemData::fromModel($item, $library)`, `Permission::ManageMedia`.
- Produces: `201` with `MediaItemData` JSON; `403` without the capability; `422` for SVG, oversize, foreign brand.

- [x] Tests: uploads a PNG and gets the item back with `variantsPending: true`; refuses without `ManageMedia`; refuses an SVG renamed to `.png` (`mimes` judges content); refuses a brand id that does not exist; records `uploaded_by_user_id`.
- [x] Implement `quickStore()` as `store()` minus the redirect — the same Action, a `JsonResponse`. Keep `store()`'s redirect behaviour; both validate through the new Data.
- [x] `flock <lock> vendor/bin/sail composer verify -- platform`; commit `Answer a quick media upload with the item as JSON so a form can pick it without leaving (#292).`

### Task 2: `MediaPicker.vue` gains Upload

**Files:**
- Modify: `resources/js/components/admin/MediaPicker.vue` (new `brandId: string` prop, `canUpload?: boolean` prop, an upload tab inside the existing dialog; the candidate list becomes a local `ref` seeded from `props.media` and watched)
- Modify every consumer to pass `brandId` and `canUpload`: `resources/js/pages/admin/brands/Edit.vue`, `app-modules/commerce/resources/js/pages/admin/products/Edit.vue`, `app-modules/platform/resources/js/components/blocks/editors/HeroEditor.vue` (and whatever page feeds it the brand)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` — shared prop `can.manageMedia` if no equivalent exists yet (check `auth.can` first; reuse it if the console already shares capabilities)
- Test: `resources/js/components/admin/MediaPicker.test.ts` (vitest: upload success prepends and selects; a client-side oversize file is refused with a message and never sent; the upload tab is absent when `canUpload` is false)

**Interfaces:**
- Consumes: Wayfinder `MediaAdminController.quickStore`, Inertia v3 `useHttp` (multipart `FormData`), `MediaItemData`.
- Produces: `update:modelValue` with the new ULID.

- [x] Client checks `accept="image/jpeg,image/png,image/gif,image/webp"` and `file.size <= 10 * 1024 * 1024` before sending; the server's 422 message is shown under the input when it still refuses.
- [x] The selected tile shows `url` while `thumbUrl` is null — already the picker's fallback; verify, do not reimplement.
- [x] `npx prettier --check` on the touched files; `npx vitest run resources/js/components/admin`; commit `Let the media picker upload a picture on the spot for anyone who may manage media (#292).`

### Task 3: Product and brand forms prove the round-trip

**Files:**
- Modify: `app-modules/commerce/tests/ProductAdminTest.php`, `app-modules/platform/tests/BrandAdminTest.php` (or their nearest equivalents — find the test that already asserts the picker's `media` prop)

- [x] One feature test per form: the page's Inertia props carry `brandId` next to `media`, and a quick upload for that brand followed by a save with the returned id stores it and records usage.
- [x] Commit `Prove the brand and product forms can pick a picture they just uploaded (#292).`

---

# Part B — Event: the banner (§5a)

### Task 4: `events.banner_media_id`, `EventData`, the two Actions, the editor

**Files:**
- Create: `app-modules/event/database/migrations/2026_09_18_100000_add_banner_media_id_to_events_table.php` (nullable `ulid`, no FK)
- Modify: `app-modules/event/src/Models/Event.php` (fillable), `app-modules/event/src/Data/EventData.php` (`bannerMediaId: ?string = null`, rule `nullable|ulid`)
- Modify: `app-modules/event/src/Actions/CreateEvent.php`, `app-modules/event/src/Actions/UpdateEventDetails.php` — save the column; refuse a media id whose `MediaLibrary::belongsToBrand()` is false (`ValidationException` on `bannerMediaId`); sync usage under `('event', 'event', $event->id)` copying `UpdateBrand::syncMediaUsage()`
- Modify: `app-modules/event/src/Http/Controllers/Admin/EventAdminController.php` (or wherever `admin/events/Edit` is rendered) — pass `media: pickableImages($brandId)` and `brandId`
- Modify: `app-modules/event/resources/js/pages/admin/events/Edit.vue` — `MediaPicker` under the description, label "Banner", `brandId`, `canUpload`
- Test: `app-modules/event/tests/EventBannerTest.php`

- [x] Tests: create and update store the id and record usage; changing it releases the old and records the new; another brand's media is refused; clearing it releases usage; the editor page carries `media` and `brandId`.
- [x] `types:generate`; commit `Give an event a banner from the brand's media library (#292).`

### Task 5: `EventCreated` and `EventDetailsUpdated` carry the banner

**Files:**
- Modify: `app-modules/event/src/Events/EventCreated.php`, `app-modules/event/src/Events/EventDetailsUpdated.php` (`public ?string $bannerMediaId = null`, last parameter)
- Modify: every producer — `CreateEvent`, `UpdateEventDetails`, `app-modules/event/src/Console/ReplayEventDetailsCommand.php`
- Modify: `app-modules/event/SPEC.md` (the contract table)
- Test: extend `app-modules/event/tests/EventManagementTest.php` and `ReplayEventDetailsCommandTest.php`

- [x] Tests: both events carry the id after create/update; replay re-fires it with the stored id.
- [x] Commit `Announce an event's banner on its created and details-updated events (#292).`

### Task 6: Both event summaries learn the banner

**Files:**
- Create: `app-modules/admission/database/migrations/2026_09_18_100100_add_banner_media_id_to_admission_event_summaries_table.php`
- Create: `app-modules/registration/database/migrations/2026_09_18_100100_add_banner_media_id_to_registration_event_summaries_table.php`
- Modify: `app-modules/admission/src/Models/EventSummary.php`, `app-modules/admission/src/Listeners/ProjectEventSummary.php`; `app-modules/registration/src/Models/EventSummary.php`, `app-modules/registration/src/Listeners/ProjectEventSummary.php` — set on `EventCreated`, **patch** on `EventDetailsUpdated` (never replace the row: `.ai/rules/tickets.md`)
- Test: extend `app-modules/admission/tests/EventSummaryProjectionTest.php` and Registration's projection test

- [x] Tests per module: created carries it; details-updated patches it and leaves times and venue alone; null clears it.
- [x] Commit `Project the event banner into Admission's and Registration's event summaries (#292).`

### Task 7: The landing page hero

**Files:**
- Modify: `app-modules/event/src/Http/Controllers/EventLandingController.php` (`show()`: `bannerUrl`, `bannerAlt` through `MediaLibrary::referenceFor()`; also `index()` for the public list if it shows cards)
- Modify: `app-modules/event/resources/js/pages/event/Landing.vue` — hero `<img>` above the blocks, `object-cover`, fixed aspect, alt text or the event name; nothing when null
- Test: extend `app-modules/event/tests/EventLandingRouteTest.php`

- [x] Tests: props carry the URL for a banner; null without one; null when the media row is gone.
- [x] `npm run build`; commit `Show the event banner as the landing page's hero (#292).`

### Task 8: Messaging renders a hero the sender names

**Files:**
- Modify: `app-modules/messaging/src/Data/SendMessageRequest.php` (`heroImageUrl: ?string = null`, `heroImageAlt: ?string = null`), `app-modules/messaging/src/Mail/MailLayout.php` (hand both to the view), `app-modules/messaging/resources/views/layouts/branded.blade.php` (one full-width `<img width="600" style="display:block; width:100%; height:auto; border:0;">` row between header and body, only with a URL), `app-modules/messaging/src/Actions/SendMessage.php` (thread the two fields; the log row may ignore them)
- Modify: `app-modules/messaging/SPEC.md`
- Test: `app-modules/messaging/tests/MailLayoutTest.php` (extend: parse the HTML, assert the `<img>` row exists with the URL and alt, and is absent without)

- [x] Commit `Let a mail carry a hero image row above its body (#292).`

### Task 9: The lifecycle mails and the confirmation carry the banner

**Files:**
- Modify: `app-modules/registration/src/Mail/RegistrationMailer.php` — `send()` resolves `registration_event_summaries.banner_media_id` (the summary it already reads for `eventName`) through `MediaLibrary::referenceFor()` and sets `heroImageUrl`/`heroImageAlt` on the request
- Test: extend the mailer's test (the one asserting `registration.confirmed` is sent)

- [x] Tests: the request carries the URL when the summary has a banner; null otherwise; a missing media row yields null and the mail still sends.
- [x] Commit `Head every registration mail with the event's banner (#292).`

---

# Part C — Admission: holders and invites (§4)

### Task 10: Events — buyer on `AdmissionIssued`, `AdmissionHolderUnassigned`, `AdmissionOwnershipAnnounced` and its command

**Files:**
- Modify: `app-modules/admission/src/Events/AdmissionIssued.php` (`public readonly ?string $buyerUserId = null`), producers `IssueAdmissionsForRegistration`, `IssueCrewAdmission`
- Create: `app-modules/admission/src/Events/AdmissionHolderUnassigned.php`, `app-modules/admission/src/Events/AdmissionOwnershipAnnounced.php`
- Create: `app-modules/admission/src/Console/AnnounceOwnershipCommand.php` (`admission:announce-ownership`, iterates `Admission::withoutBrandScope()` in chunks, emits one event per row)
- Modify: `AdmissionServiceProvider` (register the command), `app-modules/admission/SPEC.md` (contract table)
- Test: `app-modules/admission/tests/AnnounceOwnershipCommandTest.php`; extend `IssueAdmissionsFromRegistrationConfirmedTest.php`

**Precedent:** `registration:announce-ownership` and `RegistrationOwnershipAnnounced`, including the structural test that only projections listen to it — copy that test for the new event.

- [x] Commit `Announce an admission's buyer on issue, a holder's removal, and a replay-safe ownership snapshot (#16).`

### Task 11: `AssignHolder`

**Files:**
- Create: `app-modules/admission/src/Actions/AssignHolder.php` (`ACTOR_MANAGER`, `ACTOR_OPERATOR`; lock, guard as `ClaimAdmission`, revoke live links, set holder + `Assigned`, `AdmissionHolderAssigned(viaClaimLink: false)`)
- Create: `app-modules/admission/src/Exceptions/HolderChangeRefusedException.php` (one class, static constructors per reason, like `ClaimLinkNotClaimableException`)
- Test: `app-modules/admission/tests/AssignHolderTest.php`

**Interfaces:** operator check through Platform's `PermissionHolders` exactly as `AssignPlacesToRegistration::guardActor()` does for `BookOnBehalf`.

- [x] Tests: manager assigns self; manager refused for a foreign registration; refused when a holder exists; refused when revoked; live link revoked in the same transaction; operator with `AssignHolder` may name anybody; operator without is refused; event carries `viaClaimLink: false`.
- [x] Commit `Let a registration's buyer name themselves the holder of one of its admissions (#16).`

### Task 12: `UnassignHolder`

**Files:**
- Create: `app-modules/admission/src/Actions/UnassignHolder.php` (lock; refuse with a `CheckIn` row; refuse revoked; null holder, `Issued`; `AdmissionHolderUnassigned`)
- Test: `app-modules/admission/tests/UnassignHolderTest.php`

- [x] Tests: manager takes back; refused after check-in; refused for a stranger; operator needs `ReassignHolder`; the seat read model is untouched (Task 27 asserts the inverse from Resources' side).
- [x] Commit `Let a buyer take an admission back from its holder until the door has seen it (#16).`

### Task 13: `RevokeClaimLink`, `SendClaimLink` and the `admission.claim-link` template

**Files:**
- Create: `app-modules/admission/src/Actions/RevokeClaimLink.php`, `app-modules/admission/src/Actions/SendClaimLink.php`, `app-modules/admission/src/Data/ClaimLinkMailData.php` (`eventName, managerName, role, claimUrl, expiresAt`)
- Modify: `AdmissionServiceProvider::registerMessageTemplates()` (new method if absent; de + en bodies in `registration.waitlist-offer`'s shape; `sample` for the console preview)
- Test: `app-modules/admission/tests/ClaimLinkInviteTest.php`

**Interfaces:** `SendMessage::handle(new SendMessageRequest(recipientId: null, recipientEmail: $link->intended_email, templateKey: 'admission.claim-link', payload, brandId, heroImageUrl/Alt from `admission_event_summaries`))`. Manager name from `admission_people`. Failure → `Log::warning`, never a rollback (the link stays).

- [x] Tests: mail goes to the bound address with the signed URL; an unbound link sends nothing; revoke sets `revoked_at` and is idempotent; a send failure leaves the link live.
- [x] Commit `Mail a claim link to the address it is bound to, and let its issuer revoke it (#16).`

### Task 14: Routes, controllers and Data for the manager's actions

**Files:**
- Modify: `app-modules/admission/routes/web/admission.php` (§4.3: `POST|DELETE /my/admissions/{admission}/holder`, `POST|DELETE /my/admissions/{admission}/invite` with `throttle:20,1`)
- Create: `app-modules/admission/src/Http/Controllers/HolderController.php`, `app-modules/admission/src/Http/Controllers/InviteController.php`, `app-modules/admission/src/Data/InviteData.php` (`email: ?string`, `nullable|email:rfc,dns`)
- Create: `app-modules/admission/src/Support/FindManagedAdmission.php` (loads by id where `buyer_user_id = auth`, 404 otherwise — the ownership check every manager route shares)
- Test: `app-modules/admission/tests/HolderRoutesTest.php`, `app-modules/admission/tests/InviteRoutesTest.php`

- [x] `InviteController::store` issues, sends when an address was posted, flashes `inviteUrl` (built by `urlFor()`) so the panel can show the copy field; `destroy` revokes.
- [x] Tests: each route for manager (200/redirect), holder-only user (404), stranger (404), anonymous (login); the throttle answers 429 on the 21st invite.
- [x] Commit `Open the holder and invite actions to a registration's buyer over HTTP (#16).`

### Task 15: The invitee's landing page

**Files:**
- Modify: `app-modules/admission/routes/web/admission.php` (`GET /admissions/claim/{link}`, `signed`, `auth` — the login redirect keeps the intended URL, which Fortify does by default; verify)
- Modify: `app-modules/admission/src/Http/Controllers/ClaimController.php` (`show()`; `store()` redirects to `admission.tickets.show` on success)
- Create: `app-modules/admission/src/Data/ClaimLandingData.php` (§4.4), `app-modules/admission/resources/js/pages/admission/claim/Show.vue`
- Test: extend `app-modules/admission/tests/AdmissionClaimLinkTest.php`

- [x] Tests: renders for a live link with event, role, manager name and expiry; 410 for claimed/revoked/expired; a bound link's page shows the address it is meant for; the flag `alreadyHolderOfAnother` is true when the visitor holds another admission of the event; anonymous → login → back to the link.
- [x] `npm run build`; commit `Show an invitee what they are claiming before they claim it (#16).`

### Task 16: `TicketManagerSlot.vue` replaces the download card

**Files:**
- Create: `app-modules/admission/src/Data/TicketManagerData.php`, `ManagedAdmissionData.php`, `InviteStateData.php` (§4.4; `seat` stays null until Task 28 fills it)
- Modify: `AdmissionServiceProvider::registerSlots()` — the closure builds `TicketManagerData` (admissions of the registration, people, live links, check-ins — four queries, no N+1: `Model::shouldBeStrict()` is on)
- Create: `app-modules/admission/resources/js/components/TicketManagerSlot.vue` and extracted `TicketManagerRow.vue`, `InviteForm.vue` (ADR-0038 guard on the form)
- Delete: `TicketLinkSlot.vue`, `TicketLinkData.php` (after every reference is gone)
- Test: rewrite `app-modules/admission/tests/RegistrationTicketSlotTest.php`; vitest for the row's action visibility matrix

- [x] Rows and actions exactly as §4.5: *This one is mine* / *Invite* / *Copy link* / *Revoke* / *Take back* / *Ticket*, by state; download-all stays at the top.
- [x] Commit `Turn the registration page's ticket card into the buyer's ticket manager (#16).`

### Task 17: Metrics for holders and invites

**Files:**
- Modify: `app-modules/admission/src/Metrics/AdmissionMetrics.php` (catalogue), create `app-modules/admission/src/Listeners/Metrics/RecordHolderChangeMetrics.php`, `RecordClaimInviteMetrics.php` (on `AdmissionHolderAssigned`, `AdmissionHolderUnassigned`, and two new internal events `ClaimLinkIssued`/`ClaimLinkSent`/`ClaimLinkRevoked` if the Actions do not already emit — prefer emitting from the Actions)
- Modify: `contrib/observability/src/sale.mjs` — row "Holders and invites"; `contrib/observability/rules/*.yaml` — `KonventixClaimInviteMailFailing` (warning, `send_failed > sent` over 30 m, runbook anchor in `docs/operations.md`)
- Test: extend `app-modules/admission/tests/AdmissionMetricsTest.php`; `ObservabilityCoverageTest` must pass

- [x] Commit `Count holder changes and claim invites, and alert when invite mail stops arriving (#16).`

---

# Part D — Resources: a seat per admission (§6)

### Task 18: `resource_admissions` read model and its projector

**Files:**
- Create: migration `2026_09_18_100200_create_resource_admissions_table.php` (§6.1), `app-modules/resources/src/Models/ResourceAdmission.php` (`BelongsToBrand`, non-incrementing string key), `app-modules/resources/src/Listeners/ProjectAdmission.php` (on `AdmissionIssued`, `AdmissionHolderAssigned`, `AdmissionHolderUnassigned`, `AdmissionRevoked`, `AdmissionOwnershipAnnounced`; ungated; `withoutBrandScope()` + explicit `brand_id`)
- Modify: `ResourcesServiceProvider::registerListeners()`, `app-modules/resources/SPEC.md` (consumed events)
- Test: `app-modules/resources/tests/AdmissionProjectionTest.php`

**Precedent:** `ProjectEventWindow` and Admission's `ProjectEventSummary`.

- [x] Commit `Give Resources its own read model of admissions, fed by Admission's events (#292).`

### Task 19: `resource_bookings.admission_id`, the partial unique index, and the three events

**Files:**
- Create: migration `2026_09_18_100300_add_admission_id_to_resource_bookings_table.php` (nullable ulid, index `(brand_id, admission_id)`, `DB::statement` partial unique `resource_bookings_one_place_per_admission` on `(admission_id) WHERE admission_id IS NOT NULL AND state IN ('held','confirmed')`)
- Modify: `ResourceBooking.php` (fillable), `HoldResourceBooking.php` (optional `?string $admissionId = null`, written on the row), `ResourceBookingHeld.php`, `ResourceBookingConfirmed.php`, `ResourceBookingReleased.php` (`public ?string $admissionId = null`), and the producers that build these events from rows (`ConfirmResourceBooking`, `ReleaseResourceBooking`, the sweep)
- Modify: `app-modules/resources/SPEC.md`
- Test: extend `app-modules/resources/tests/ResourceBookingTest.php`

- [x] Tests: a hold with an admission id writes it and the three events carry it; a second occupying booking for the same admission fails the index inside the Action's savepoint; released rows do not count.
- [x] Commit `Let a booking name the admission it seats, one place per admission by the schema (#292).`

### Task 20: `AssignPlaceToAdmission`

**Files:**
- Create: `app-modules/resources/src/Actions/AssignPlaceToAdmission.php` (§6.3, steps 1–7 in that order; reuse `AssignPlacesToRegistration`'s eligibility query and race handling by extracting both into a `Concerns\PicksPlaces` trait rather than copying)
- Modify: `app-modules/resources/src/Actions/AssignPlacesToRegistration.php` (uses the trait; behaviour unchanged)
- Test: `app-modules/resources/tests/AssignPlaceToAdmissionTest.php`

- [x] Tests: holder picks own; manager picks any admission of the registration; stranger refused; `assign` mode refused for both; cap across bound + unbound honoured; picking the group's unbound place rebinds it; repick releases the old with `repicked`; null place gives the seat up and the claim goes `unplaced` only when no booking remains; revoked admission refused; race → `placeIds` validation error and `ResourceBookingRefused`.
- [x] Commit `Seat one admission on one place, picked by its holder or its buyer (#292).`

### Task 21: Per-admission picker routes, `FindSeatClaimForAdmission`, `SeatPickerData`

**Files:**
- Modify: `app-modules/resources/routes/web/resources.php` (§6.4 routes)
- Create: `app-modules/resources/src/Actions/FindSeatClaimForAdmission.php`, `app-modules/resources/src/Http/Controllers/AdmissionSeatController.php` (`show`, `store`, `availability`), `app-modules/resources/src/Data/PickPlaceData.php` (`placeId: ?string`)
- Modify: `app-modules/resources/src/Data/SeatPickerData.php` (`admissionId: ?string`, `maxPlaces: int`)
- Test: `app-modules/resources/tests/AdmissionSeatControllerTest.php`

- [x] Tests: show for holder, for manager, 404 otherwise; store binds; availability answers for either; the registration-level routes still work for a registration with no admissions in the read model.
- [x] `types:generate`; commit `Open the seat picker per admission (#292).`

### Task 22: `Pick.vue` single-select and the per-admission summary

**Files:**
- Modify: `app-modules/resources/resources/js/pages/seating/Pick.vue`, `app-modules/resources/resources/js/components/plan/*` (selection model honours `maxPlaces`; the list view's `aria-pressed` follows; submit posts `placeId` to the admission route when `admissionId` is set)
- Modify: `app-modules/resources/src/Data/SeatSummaryData.php` (`admissions: list<AdmissionSeatRowData {admissionId, placeRef, placeLabel, holderUserId, pickUrl}>`), `ResourcesServiceProvider::registerSlots()` (rows from `resource_bookings.admission_id` + `resource_admissions`), `SeatSummarySlot.vue` (per-admission rows; no link to the registration picker when admissions exist)
- Test: vitest on the selection model; extend the slot's feature test

- [x] Commit `Show each ticket's seat on the registration page and pick one seat at a time for a ticket (#292).`

### Task 23: A revoked admission frees its seat

**Files:**
- Create: `app-modules/resources/src/Listeners/ReleaseBookingOnAdmissionRevoked.php` (ungated, reason `admission_revoked`), register it
- Test: `app-modules/resources/tests/AdmissionRevocationTest.php`

- [x] Tests: release on revoke; an unassigned holder (`AdmissionHolderUnassigned`) keeps the booking.
- [x] Commit `Free a seat when its admission is revoked, keep it when the holder merely changes (#292).`

### Task 24: Seat metrics

**Files:**
- Modify: `app-modules/resources/src/Metrics/ResourcesMetrics.php`, `src/Listeners/Metrics/RecordBookingMetrics.php` (or a new recorder) for `resource_admission_seats_total{outcome}`; `contrib/observability/src/sale.mjs` Seating row
- Test: extend `app-modules/resources/tests/ResourcesMetricsTest.php`

- [x] Commit `Count seats placed, given up and refused per ticket (#292).`

---

# Part E — Admission: the ticket page (§4.5, §5)

### Task 25: `admission_seats` read model

**Files:**
- Create: migration `2026_09_18_100400_create_admission_seats_table.php`, `app-modules/admission/src/Models/AdmissionSeat.php`, `app-modules/admission/src/Listeners/ProjectAdmissionSeat.php` (on `ResourceBookingConfirmed` with a non-null `admissionId` → upsert; `ResourceBookingReleased` → delete by `booking_id`)
- Modify: `AdmissionServiceProvider`, `app-modules/admission/SPEC.md`
- Test: `app-modules/admission/tests/AdmissionSeatProjectionTest.php`

- [x] Commit `Let Admission know which place each of its admissions sits on (#292).`

### Task 26: `TicketQr` and the ticket page

**Files:**
- Create: `app-modules/admission/src/Documents/TicketQr.php` (`svg(string $token): string`, `dataUri(string $token): string`); `TicketDocumentRenderer` uses it in place of its private helper
- Create: `app-modules/admission/src/Data/TicketPageData.php`, `TicketSeatData.php` (§4.4), `app-modules/admission/src/Http/Controllers/TicketPageController.php` (`show`: holder or manager, 404 otherwise; `current()` for the token)
- Modify: routes (`GET /my/tickets/{admission}`, `admission.tickets.show`)
- Create: `app-modules/admission/resources/js/pages/admission/tickets/Show.vue` and components `TicketEventBand.vue` (banner backdrop + scrim), `TicketHolderBand.vue` (QR box `v-html`, door code, `role="img"`), `TicketActions.vue`
- Modify: `app-modules/admission/resources/js/pages/admission/tickets/Index.vue` (rows link to the page; "Tickets you manage" section — `MyTicketsController` gains the managed list)
- Test: `app-modules/admission/tests/TicketPageTest.php`; extend `MyTicketsControllerTest.php`

- [x] Tests: page for holder and for manager; 404 for others; `qrSvg` decodes to the same token the PDF carries; `bannerUrl` from the summary, null when unresolvable; seat present after a bound booking; `canPickSeat` follows §6.3's actor rule and `assign` mode.
- [x] `types:generate`, `npm run build`; commit `Give every ticket a page of its own with its code, seat and banner (#292).`

### Task 27: The PDF names the seat and wears the banner

**Files:**
- Modify: `app-modules/admission/src/Documents/TicketDocumentRenderer.php` (seat line in the holder band from `admission_seats`; banner `<img>` at the top of the event band from `MediaLibrary::dataUriFor($summary->banner_media_id)`; watermark layers stay before content), the Blade view
- Test: extend `app-modules/admission/tests/TicketDocumentTest.php` (magic bytes + length with and without banner; the structural layer-order test now also asserts the banner sits after the watermark layers and before the QR box)

- [x] Commit `Print the seat and the event banner on the ticket (#292).`

### Task 28: The manager panel and `ManagedAdmissionData` show the seat

**Files:**
- Modify: `AdmissionServiceProvider::registerSlots()` (fill `seat` from `admission_seats`), `TicketManagerRow.vue` (seat chip, link to the per-admission picker via Wayfinder `AdmissionSeatController.show`)
- Test: extend `RegistrationTicketSlotTest.php`

- [x] Commit `Show each ticket's seat in the buyer's ticket manager (#292).`

---

# Part F — GDPR, docs, hand-off

### Task 29: GDPR

**Files:**
- Modify: `app-modules/admission/src/Gdpr/AdmissionGdprHandler.php` (§10), `app-modules/resources/src/Gdpr/ResourcesGdprHandler.php` (§10)
- Test: extend `AdmissionGdprHandlerTest.php` and Resources' handler test

- [x] Commit `Export and erase what the holder work and the seat read models know about a person (#16).`

### Task 30: Backfill on dev, documentation, rules

- [ ] Run once on the dev cluster after deploy, in this order: `event:replay-details` (banners into both summaries), `admission:announce-ownership` (Resources' admission read model). Record both in `docs/operations.md` next to `registration:announce-ownership`.
- [x] `SPEC.md` of admission, resources, event, messaging, platform: the new events, read models, routes and the picker's upload door. `TODO.md` of each: check off #16 points 1 and 3, add the console (point 2), the `banner` variant, drag-and-drop upload.
- [x] `.ai/rules/tickets.md`: three new entries — *one place per admission is the partial index, not a check*; *`AdmissionOwnershipAnnounced` is projection-only*; *the mail hero is the layout's, never a template's `<img>`*. `.ai/rules/seating.md`: *`admission_id` is written by `HoldResourceBooking` only; the registration-level pick path still exists for registrations without admissions*.
- [x] `docs/architecture/module-map.md`: Admission ↔ Resources read models.
- [x] Commit `Document the holder, seat-per-ticket and banner work (#16).`

### Task 31: Verify before the merge request

- [ ] `flock <lock> vendor/bin/sail composer verify -- platform event messaging registration admission resources`
- [ ] `npx prettier --check resources/ "app-modules/*/resources/**/*.{ts,tsx,vue,js,css,json}"`; `npx vitest run`
- [ ] Push; the pipeline runs the full suite. Open the MR against `develop` referencing #16 and #292; the description lists the two backfill commands the deploy needs.

---

## Ordering and parallelism

Parts A and B are independent of C–E and of each other; B's Task 8 must precede Task 13 (the claim mail wants the hero). Within C: 10 → 11 → 12 → 13 → 14 → 15 → 16 → 17. Within D: 18 → 19 → 20 → 21 → 22 → 23 → 24, and 18 depends on Task 10. Part E: 25 depends on 19; 26 depends on 6, 21, 25; 27 on 25 and 6; 28 on 25 and 21. Two workers can run A+B and C in parallel from the start; D starts once Task 10 has landed.
