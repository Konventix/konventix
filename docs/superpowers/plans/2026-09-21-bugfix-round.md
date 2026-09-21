# Bugfix Round 2026-09-21 Implementation Plan

> **For agentic workers:** Tasks are independent by module and may run in parallel; test runs are serialised through one lock. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nine reported defects on the dev instance are fixed on `develop` without new issues: the payer lands on their order after paying, the ticket PDF renders fast with an uncropped banner, a submitted registration leaves the form, the seat picker works by click with swap-on-click and hover labels behind an accessibility toggle, a Steam-only account can set a password, and the notification bell stops saying "Loading…".

**Architecture:** Every fix stays inside the module that owns the surface (ADR-0003/0004). No new dependencies, no new documentation files beyond this plan. No PDF caching: the ticket stays "rendered fresh on every request" (tickets design §176, `TicketDocumentTest` "stores no PDF on disk").

**Tech Stack:** Laravel 12 / PHP 8.5 on Octane, spatie/laravel-data with generated TypeScript, Inertia v3 + Vue 3, Tailwind v4 semantic tokens, dompdf, Pest 5, vitest.

**Investigation basis:** six read-only code investigations on 2026-09-21 against `develop` at `a624192`.

## Global Constraints

- Work directly on `develop` (operator decision 2026-09-21), pushed over HTTPS with glab's token. One commit per task, prose subject naming the fix, ending with the attribution lines of the session (`Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_0195KvC2hZjXqCtMwPPiFzEh`).
- The formatting hook is not active in this session (it started in the multi-repo parent). After editing, run `vendor/bin/sail bin pint <changed php files>` and `npx prettier --write <changed frontend files>` then `npx eslint --fix <changed frontend files>` by hand.
- Every test run is serialised: `flock /tmp/claude-1000/-home-mkohn-git-konventix/081c6f55-6722-4fab-901f-82ffd829a91d/scratchpad/sail.lock vendor/bin/sail artisan test --compact <path or --filter>`. `vendor/bin/sail npm run build` goes through the same lock. Never two test processes at once (`.ai/rules/tests.md`).
- `vendor/bin/sail composer types:generate` then `npx prettier --write resources/js/types` after any Data class change; `vendor/bin/sail artisan wayfinder:generate` after any route change; `vendor/bin/sail npm run build` before a Pest test that renders a new Inertia page.
- Read `CLAUDE_extra.md`, `.ai/rules/index.md` and every rule file whose glob matches the paths of a task before editing. Read the module's `SPEC.md`; update its `TODO.md` in the same commit (check off, add follow-ups).
- Frontend: Wayfinder helpers only, semantic tokens only, English UI strings, `#[TypeScript]` on every Data class, no hand-written TS for backend payloads (ADR-0009). Vitest runs in the `node` environment with no `@vue/test-utils`: push logic into pure modules and test those; DOM wiring is proven by the Pest page tests.
- Agents do not commit; the session commits each task with module-scoped `git add` after review.

## Out of scope (feature requests from the same list, need a design first)

Registration-type entry conditions (student/apprentice tickets); policies bound to an event, a registration type, a payment provider or an entry condition; contract policies re-consented on login or before critical actions. These change the Compliance and Registration models and get a spec, not a bugfix commit.

---

## Task 1: Seat picker — click, swap, accessibility toggle, labels (Resources)

**Files:** `app-modules/resources/resources/js/components/plan/useScenePointer.ts`, `SeatPlanScene.vue`, `PlanPlace.vue`, `PlanBackdrop.vue`, `geometry.ts`, `eligibility.ts`, `labels.ts`, `pages/seating/Pick.vue`; tests `geometry.test.ts`, `eligibility.test.ts`, `labels.test.ts`, `app-modules/resources/tests/SeatPickPageTest.php`, `AdmissionSeatControllerTest.php`.

**Root causes:** `useScenePointer.ts:93` takes pointer capture on the `<svg>` on every pointerdown, so the browser retargets the derived `click` to the svg and the per-place `@click` (`SeatPlanScene.vue:247`) never fires. Secondary: `PlanPlace.vue:145` draws an oversized invisible hit circle, so the last-painted place swallows clicks in dense rows. Swap is blocked only by the cap logic (`eligibility.ts:44-49, 88-101`); persistence is already deferred to the "Save my seat" form (`Pick.vue:319-353`) and the backend takes a full place set (`PickPlacesData`, `AssignPlacesToRegistration` releases with reason `repicked`). Per-seat labels are drawn by `PlanPlace.vue:134-144`; there is no tooltip; fixtures and groups have no label (`PlanBackdrop.vue`).

- [x] `geometry.ts`: add pure `hitTestPlaces(places, point, radius)` returning the place whose centre is nearest within the radius (or inside its box for large places), else `null`. Tests in `geometry.test.ts`: nearest centre beats paint order; outside every radius returns null; a large place is hit anywhere inside its box.
- [x] `useScenePointer.ts` / `SeatPlanScene.vue`: keep pointer capture (pan and pinch need it), emit the selection from `pointerup` when `!wasDragged()` via `svgPoint()` + `hitTestPlaces`; drop the per-place `@click` and the invisible hit circle in `PlanPlace.vue`. The editor's `PlanCanvas.vue` is unaffected (it hit-tests on pointerdown itself).
- [x] `eligibility.ts`: add `selectWithSwap(selected, placeId, quantity)`: present → remove; under cap → append; at cap → drop the oldest and append (quantity 1 = replace). Add a `swap` reason so a free place at the cap is selectable and not hatched (`PlanPlace.vue:57`). `Pick.vue` `onToggle` uses it; the list view keeps the same handler. Tests in `eligibility.test.ts` for replace at 1, oldest-out at 2, deselect on re-click, and `placeReason`/`isSelectable`/`stateText`/`hintFor` for `swap`.
- [x] `Pick.vue`: `showList = ref(false)`; a focusable `<Button type="button" aria-pressed aria-controls="plan-list" aria-expanded>` labelled "Accessibility" placed before the scene in DOM order; the list section (`id="plan-list"`) renders only when it is on, and focus moves into the list when revealed. Reword the scene `aria-label` (`SeatPlanScene.vue:224`) so it names the Accessibility button instead of "the list below".
- [x] Labels: remove the `<text>` block and `showLabel` prop plumbing from `PlanPlace.vue` and `SeatPlanScene.vue`; add `<title>{{ placeLabel(element) }}</title>` as the first child of each place `<g>` (native hover tooltip) plus `aria-label`; in `PlanBackdrop.vue` keep zone labels, add group labels (`containerLabel(element, 'Group')`) and fixture labels, gated with `labelsVisible(zoom, pxPerUnit, 400)` (pass `showLabels` down). Extend `labels.test.ts` for the container font threshold.
- [x] Pest: `SeatPickPageTest` swap case (claim confirmed on A, post `[B]`, B confirmed, A released with reason `repicked`, one occupying booking); same guard in `AdmissionSeatControllerTest` for the per-ticket path.
- [x] `vendor/bin/sail npm run build`, `npm run types:check`, vitest for the module, `flock … vendor/bin/sail artisan test --compact app-modules/resources/tests`. Update `app-modules/resources/TODO.md` (the multi-claim picker follow-up stays).

## Task 2: Ticket PDF — banner cover and render speed (Admission, Platform media)

**Files:** `app-modules/admission/resources/views/tickets/document.blade.php`, `app-modules/admission/src/Documents/TicketDocumentRenderer.php`, `app-modules/admission/src/Actions/IssueAdmissionToken.php`, `app-modules/platform/src/Media/ThumbnailGenerator.php`, `MediaLibrary.php`; tests `app-modules/admission/tests/TicketDocumentTest.php`, `TicketDownloadControllerTest.php`, `app-modules/platform/tests/*Thumbnail*`.

**Root causes:** dompdf has no `object-fit`, so `document.blade.php:84` stretches the banner into the 100% × 22mm band; dompdf does implement `background-size: cover`. Slowness: full-resolution originals are base64-inlined (`TicketDocumentRenderer.php:81, :148`, no variant, three storage round trips per image on S3); the brand logo is resolved per page and embedded twice per page; `IssueAdmissionToken::current()` queries the signing key per page; the QR is a large SVG per page.

- [x] Blade: paint the banner as `background-image` on `.banner` with `background-size: cover; background-position: center; background-repeat: no-repeat`, drop the `<img>`. Structural pin in `TicketDocumentTest` ("paints the banner …", `:303`): the band uses `background-size: cover`, never `object-fit`.
- [x] `ThumbnailGenerator`: add a `ticket_banner` variant, cover-cropped with GD to the band's aspect ratio (compute it from the template's usable width and the 22mm height), about 1240 px wide, generated at upload beside `thumb`. `MediaLibrary::dataUriFor()` callers in the renderer prefer `ticket_banner`, then `thumb`, then the original (legacy rows have no variant). Test in the platform media tests for the crop dimensions; extend `TicketDocumentTest:275` for the smaller payload when a variant exists.
- [x] Renderer: hoist the brand logo out of `pageDataFor()` into `render()` (mirror the banner dedupe at `:77-81`); extend `TicketDocumentTest:166` with a multi-page case asserting the logo is resolved once.
- [x] `IssueAdmissionToken`: memoise the signing key per brand for the lifetime of the action instance (check the container binding is not a singleton; under Octane a singleton memo would leak across requests). `current()`/`rotate()` semantics unchanged (`.ai/rules/tickets.md`). Query-count assertion in `TicketDownloadControllerTest:19` that media and signing-key queries stay constant in page count.
- [x] Do not add PDF caching; keep `TicketDocumentTest:110` green. Update `app-modules/admission/TODO.md` (note the QR-as-SVG and font subsetting as measured follow-ups if not done).

## Task 3: Post-payment landing on the order (Commerce)

**Files:** `app-modules/commerce/routes/web/commerce.php`, `src/Http/Controllers/MyOrdersController.php`, `src/Data/MyOrderDetailData.php` (new), `resources/js/pages/commerce/my/Show.vue` (new), `Index.vue`, `src/Actions/PlaceOrderFromCart.php`, `src/Http/Controllers/CartController.php`, `CheckoutController.php`, `resources/js/pages/pay/Order.vue`; tests `CartCheckoutTest.php`, `MyOrdersTest.php`, `OrderRepayPageTest.php`, `CheckoutPageTest.php`, `CheckoutAuthorizationTest.php`, `StripeAdapterTest.php`.

**Root causes:** the cart checkout hands every adapter `route('commerce.cart.show')` as the single return URL (`CartController.php:293`), and the cart is `checked_out` by then, so the payer sees "Your cart is empty". The repay page `pay/Order.vue` has no paid branch and greets a successful payer with the red "already settled" refusal. There is no `/my/orders/{order}` page. All adapters take one URL for success, cancel and failure by contract (`ProvidesHostedCheckout`), so the landing page must branch on order state.

- [x] Route `GET /my/orders/{order}` → `commerce.mine.show` in the `auth` group, `->whereUlid('order')`; `MyOrdersController::show()` with the payer-only rule as a 404 (`CheckoutController::abortUnlessPayer()` shape, #185). Wayfinder regenerate.
- [x] `MyOrderDetailData` (`of*` naming, `#[TypeScript]`): `MyOrderData`'s fields plus `totalNet`, `totalTax`, `paymentStatus` of the latest payment, `paidAt`, billing party, `repayDueOn`. Types regenerate.
- [x] `commerce/my/Show.vue` (under 300 lines): order summary and lines, invoice and credit-note buttons; banner "Payment received" when paid; "Waiting for the payment confirmation from the provider" while the order is pending with a pending payment (reuse the copy of `pay/Show.vue:181-200`); the existing `payUrl` "Pay now" button when payable (this is what keeps the cancel path working). `Index.vue` links each card to the detail page through the generated helper.
- [x] `PlaceOrderFromCart::handle()` takes a `Closure(Order): string` for the return URL and builds it in `sendToProvider()`; `CartController.php:293` passes the order detail route; `CheckoutController.php:272` and `:424` return to the order detail page too, so every hosted checkout lands in one place. Add the missing paid banner to `pay/Order.vue` anyway (it is still reachable by link).
- [x] Tests: `CartCheckoutTest` pins the return URL handed to the fake Stripe API to the placed order's detail page (replacing the unpinned `'https://brand.test/cart'` literals where the controller is exercised); new `MyOrderDetailTest`: renders lines, invoice and credit notes; 404 for a non-payer and for another brand's order; guest → login; paid banner; waiting banner while the payment row is pending; "Pay now" for a pending order. `OrderRepayPageTest:243` asserts the confirmation on a paid order. `CheckoutPageTest:152` pins the registration return URL. `StripeAdapterTest` asserts `success_url`/`cancel_url`. `CheckoutAuthorizationTest` gains the new route.
- [x] `npm run build` before the page tests; `flock … vendor/bin/sail artisan test --compact app-modules/commerce/tests`. Update `app-modules/commerce/TODO.md`.

## Task 4: Registration form leaves the page after submit (Registration)

**Files:** `app-modules/registration/src/Http/Controllers/RegistrationController.php`; tests `InstantCartHandoffTest.php`, `PublicRegistrationFlowTest.php`, `CartLineCancellationTest.php`.

**Root cause:** the form is a full page (`registration/Register`), not a dialog. `RegistrationController::store()` `:93-95` redirects a successful submit to `registration.show`, which re-renders the empty form with only a toast. The cart handoff (`:80-90`) needs `commerce:shop` on, which defaults to off per brand, so an `on_payment` type on sxlan.dev takes the fallback and the payer never sees their pay page. The pay page for a direct order exists (`commerce.pay.show`, `/pay/registration/{registration}`) and is unused by Registration.

- [x] `store()`: keep the cart branch; otherwise, when the type requests settlement and `Route::has('commerce.pay.show')`, redirect to `commerce.pay.show` for the registration (its controller hands off to the cart itself when no direct order exists); otherwise redirect to `registration.mine.show` for the new registration. Waitlist branch (`:72-74`): redirect to where the person can see their queue place (check whether `/my/registrations` lists waitlist entries; else the event landing page) with the existing toast. Never `registration.show` as a success target.
- [x] Tests: re-point `InstantCartHandoffTest` `:84, :96, :107, :132` (the `:99` case "on its own pay page" now asserts `/pay/registration/{id}`, one order, zero cart lines); pin `PublicRegistrationFlowTest:94` to `registration.mine.show`; update `CartLineCancellationTest:225`.
- [x] `flock … vendor/bin/sail artisan test --compact app-modules/registration/tests`. Update `app-modules/registration/TODO.md`.

## Task 5: Steam-only account sets a password (Identity)

**Files:** `app-modules/identity/src/Concerns/PasswordValidationRules.php`, `src/Data/PasswordUpdateData.php`, `src/Http/Controllers/SecurityController.php`, `resources/js/pages/settings/Security.vue`, `src/Providers/IdentityServiceProvider.php`; tests `tests/Feature/Settings/SecurityTest.php`, `tests/Feature/Auth/PasswordResetTest.php`, `app-modules/identity/tests/SocialSignupTest.php`, `LinkedAccountsTest.php`.

**Root causes:** (a) `PasswordUpdateData` demands `current_password` unconditionally (`:33-36`), unsatisfiable for a null hash, while `LinkedAccounts.vue:109-112` sends Steam-only accounts to exactly that form; (b) Fortify's `password.reset` and `password.update` routes are `guest:web`, so a signed-in person clicking the mail link is bounced to the dashboard. `User::hasPassword()` exists (`User.php:114-117`).

- [x] (a) `PasswordValidationRules::currentPasswordRulesFor(?User $user)`: `['missing']` when the account has no password, else the existing rules; `PasswordUpdateData` widens `current_password` to nullable and branches on `request()->user()` (idiom of `AdminUpdateUserData`); `SecurityController::edit()` passes `hasPassword`; `Security.vue` hides the current-password field and titles the card "Set password" when `hasPassword` is false.
- [x] (b) In `IdentityServiceProvider::throttleUnhookedFortifyRoutes()`'s booted walk, remove `guest:<guard>` from `password.reset` and `password.update` (rewrite the route's middleware array; `NewPasswordController` resolves the account from token + email, not the session).
- [x] Tests: `SecurityTest` — a `password => null` account sees `hasPassword === false`, updates with only `password`/`password_confirmation`, ends with a hash; an account with a password still cannot skip `current_password`. `PasswordResetTest` — a signed-in user gets the reset screen (200) and can complete the reset. `SocialSignupTest` end to end: Steam signup, set password from settings, log in with e-mail + password. Mirror the `hasPassword` assertion from `LinkedAccountsTest:92` for the security page.
- [x] The `verified` gate on `settings/security` stays (placeholder-email accounts set an address first, #293); note it in `app-modules/identity/TODO.md`. `flock … vendor/bin/sail artisan test --compact app-modules/identity/tests tests/Feature/Settings tests/Feature/Auth`.

## Task 6: Notification bell finishes loading (Messaging)

**Files:** `app-modules/messaging/resources/js/components/useNotificationInbox.ts`, `notificationInbox.ts`, `NotificationBell.vue`; tests `notificationInbox.test.ts`, new `useNotificationInbox.test.ts`, `app-modules/messaging/tests/InAppInboxTest.php`.

**Root cause:** `follow()` subscribes to Echo (`useNotificationInbox.ts:93-97`) before fetching the feed (`:109`); on the operator console host there is no brand and no Reverb key, so `echo()` throws inside the immediate watcher, the fetch never runs, `loaded` stays false and the bell shows "Loading…" forever. Second defect: every feed failure is swallowed with `loaded = false` (`:123-128`), so any 419/500/network error is also permanent "Loading…".

- [x] Fetch the feed first; wrap the Echo subscription in `try/catch` (skip it outright when the page's `reverb.key` is null) so a host without realtime still renders the fetched list.
- [x] Add `failed` to `InboxState` (pure reducer in `notificationInbox.ts`), set on a rejected or non-ok fetch; `NotificationBell.vue` renders "Loading…" only while neither loaded nor failed, and an error line with a "Retry" button when failed.
- [x] Vitest: `useNotificationInbox.test.ts` with `vi.mock('@laravel/echo-vue')` throwing and a stubbed `globalThis.fetch`, stubbing `window`/`document.cookie` in the file (no new dependency): the feed loads despite the Echo failure; a rejected fetch yields `failed`, not endless loading. Extend `notificationInbox.test.ts` for the new reducer.
- [x] Pest: `InAppInboxTest` gains an operator-host case (`admin_host` pattern from `MailSettingsAdminTest:25`): `GET /notifications/feed` returns 200 with `unread: 0` and an empty list.
- [x] `npx vitest run app-modules/messaging`, `flock … vendor/bin/sail artisan test --compact app-modules/messaging/tests`. Update `app-modules/messaging/TODO.md`.

## Closing

- [ ] `vendor/bin/sail composer types:generate`, `vendor/bin/sail artisan wayfinder:generate`, `npm run lint`, `npm run types:check`, `npm run test:js` once over everything.
- [ ] Full suite once before the push: `flock … vendor/bin/sail artisan test --parallel --compact`.
- [ ] Push `develop` over HTTPS; verify the pipeline; note the six feature requests for a design session.
