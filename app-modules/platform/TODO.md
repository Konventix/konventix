# Platform — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [x] The live-event hub, Platform's half (0.9.0 sub-project A, design
      2026-09-22, tasks 8–14). `platform_hub_access` + `Models\HubAccess`
      projected from Admission's `ParticipantAdmitted`/
      `ParticipantPresenceChanged`/`ParticipantHubAccessEnded`/
      `ParticipantAdmissionReplaced` by `Listeners\ProjectHubAccess`, and
      `platform_hub_layouts` + `Models\HubLayout` projected from Event's `EventHubLayoutChanged` by
      `Listeners\ProjectHubLayout` — the first listeners Platform has ever
      had on another module's events, and SPEC's "Consumes" section now says
      why that is a read model rather than an inversion.
      `Hub\ActiveEventContext` (scoped, memoised per request *and per
      viewer*, cache `hub-access:{brand}:{user}`, TTL 600) answers
      `current()`/`all()` and feeds the shared `activeEvent` prop
      (`Data\ActiveEventData`); a guest pays nothing, pinned by a
      zero-query test. `Hub\HubRegistry` + `Hub\HubCard`/`Hub\HubLink`
      (payload and URL closures, gated by module and capability, sorted by
      `order`) with `Hub\HubCatalogue` as the read side Event's layout
      editor calls. `Hub\HubLayoutResolver` (`hub-layout:{event}`, empty
      layout cached too) and `Hub\HubPage`, which merges layout order with
      the registry, resolves each surviving closure behind its own guard —
      a contributor that throws loses its card, not the page
      (`platform_hub_card_failures_total{brand,module}`) — and counts the
      render on `platform_hub_views_total{brand}`. Routes: `/live`
      (`platform.hub`) behind `auth` + the new `ensure-active-event`
      middleware, the home switch in `StaticPageController::home()` and
      `/home` (`home.plain`) beside it. Frontend: `pages/platform/live/
      Hub.vue`, the topbar pill via `resources/js/lib/livePill.ts`.
      `Gdpr\HubAccessGdprHandler` exports and erases the rows.

- [x] `Events\BrandCreated` — the first of SPEC's proposed brand events to
      land (#287). Raised by `Actions\CreateBrand` after its transaction
      commits; Admission mints the brand's entrance signing key on it. The
      paths that build a `Brand` row directly (`konventix:install`, the dev
      and demo seeders, the factory) deliberately do not raise it — the host
      seeders run under `WithoutModelEvents`, so a model hook would be muted
      there anyway, and a consumer that needs those brands covered backfills
      on the deploy path instead.

- [x] `Targets\TargetDirectory` beside `Legal\PublicLegalTexts` (round 4,
      Task 32, ADR-0066 §7.3, D-CPL-11): a boot-time registry each owning
      module contributes a `TargetProvider` to from its own
      `registerTargetProviders()` hook, so a console can label a foreign
      entity without reading its table. `Data\TargetOptionData` lives in
      `src/Data/` rather than beside the registry, because that is the
      directory `TypeScriptTransformerServiceProvider` scans (ADR-0009).
      `tests/TargetDirectoryTest.php`.

Phase 1 — Platform carries most of the PoC risk, since every other module
depends on brand resolution and gating working.

- [ ] Operator, Brand and BrandDomain models with migrations (ULID primary keys,
      ADR-0014) and factories; operator data as rows, not config (ADR-0001).

- [ ] `BelongsToBrand` trait: global scope, automatic `brand_id` on create, and
      the `withoutBrandScope()` escape hatch behind `UseCrossBrandScope`
      (ADR-0010). Brand context serialized into queued jobs and restored in the
      worker; the base TestCase establishes a brand and resets the spatie team
      context.

## Backlog

- [ ] An event switcher on the hub page for a member admitted to more than
      one running event at once. `ActiveEventContext::all()` already answers
      with every event, newest first, and `HubPageData` carries the list;
      the page renders the first and offers no way to choose another.
- [ ] The hub cards the neighbouring sub-projects bring: Competition's
      "Nächste Spiele" and "Meine Competitions" (B), Agenda's "Jetzt /
      Gleich" (C), Community's shoutbox (D). Nothing is needed here for
      them — each module registers its own `HubCard` — but the hub is
      deliberately thin until they land.

- [x] Static pages in the brand menu, structured, with page visibility
      (bugfix round 2026-09-21, Task 20): `brand_menu_items` +
      `Models\BrandMenuItem` (`BelongsToBrand`, `inDisplayOrder`),
      `Enums\BrandMenuItemKind`/`PageVisibility`, `static_pages.visibility`,
      `Data\BrandMenuItemData`/`BrandMenuData` (replace-all, max 30, the
      footer's URL whitelist, `key`/`parentKey` for one level of hierarchy),
      `Actions\UpdateBrandMenu` (depth, destination, brand and page-ownership
      guards), `Permission::ManageBrandMenu`, `BrandMenuAdminController` at
      `/admin/menu`, `Pages\RegistrantAudience` + `Pages\PageAccess`
      (scoped, fail-closed), `Navigation\BrandMenu` → shared `brandMenu`
      prop, `StaticPageController::home()` (the `/` branch moved out of
      `routes/web.php`), `SaveStaticPage`'s idempotent "show in menu".
      Module Vue `pages/admin/pages/Menu.vue` + `components/admin/
      BrandMenuForm.vue`/`BrandMenuItemFields.vue`; host
      `BrandMenuDesktop.vue` (reka navigation menu) / `BrandMenuMobile.vue`
      (nested collapsibles) in `AppTopbar.vue`, which now honours
      `external` on desktop too. Follow-ups: the admin nav icon `menu` is
      not in `navIcons.ts` and falls back to the generic mark; the pages
      index row carries no visibility column yet; a menu entry pointing at a
      page of another brand is refused on save but nothing re-checks when a
      page later moves — pages cannot move brands today, so this is
      theoretical.

- [ ] Auth surfaces name the operator and list its brands (GitLab #184,
      ADR-0054 §5): a shared prop beside the footer data — operator name,
      `legal_name`, the operator's brands with their primary-domain URL —
      on brand hosts only, never on the operator host (ADR-0029); one
      component in the three auth layouts; a one-time first-contact notice
      when `RecordBrandMembership` writes a new row. No `listed` flag on
      `brands` until a brand has to stay unannounced before launch.

- [ ] Environment ribbon follow-ups (GitLab #68, ADR-0053): the ticket-PDF
      watermark "TESTSYSTEM" once a ticket renderer exists — ask
      `DeploymentEnvironment`, never `env()`; and the branch name on the
      ribbon for the review environments of #120, where a wildcard subdomain
      is the only other hint which instance one is on.

- [x] `permissions:sync` must run in every deploy (ADR-0013, ADR-0045) and
      nothing automates it — `konventix:migrate` runs migrations only. Decide
      whether the migrate command runs the sync afterwards or the runbook
      says so. — folded into `konventix:migrate` (ADR-0049 §3).

- [ ] Follow-ups from the console-selection review (tranche 1 of the second
      build order, 2026-08-25), none blocking: a selected brand that has since
      been deleted is not reconciled — `ConsoleSelection::brandId()` keeps
      returning it, the lists come up empty and the trigger renders blank;
      `AdminSelectionBar` never reads `page.props.errors`, so a refused
      selection is silent; the selection controller's docblock should say the
      body is the whole selection, not a patch; `ConsoleEventSources` should
      say it deliberately skips `ModuleGate` (the console has no brand);
      `HandleInertiaRequests` queries brands and every event source on every
      admin response and the list pages query brands again; the toolbar's
      `id="per-page"` / `filter-<name>` and the card's
      `id="diagnostics-heading"` are page-global; `Dashboard.vue` resolves
      each slot component twice; the toolbar's `value === clearValue` arm is
      unreachable today; the Telescope link test cannot force the production
      branch; the nav icons `activity`, `list-checks` and `building` are
      absent from `navIcons.ts` and fall back to the generic mark.

- [x] Upload from inside the picker (#292): `POST /admin/media/quick`
      answers the same `StoreMediaUpload` as JSON (`MediaItemData`) so
      `MediaPicker.vue` can add a picture to a brand, product, block or event
      form without leaving it. Same capability, same validation, no SVG.
- [ ] Drag-and-drop and multi-file upload in the picker; a `banner` variant
      beside `thumb` in `ThumbnailGenerator` (#292 follow-ups).
- [x] Object storage for the media library (ADR-0043, ROADMAP slice 17):
      `hero` blocks reference media by ULID and resolve at render (17a); the
      `media_s3` disk plus a `public_direct` delivery branch in
      `MediaLibrary` and the application media route for everything else
      (17b); queued variant generation with a pending state on remote disks
      (17c); `storage:migrate` with per-file verification and per-record
      repointing (17e); `storage:doctor`, which fetches a probe object
      anonymously to check the `public_direct` claim (17e2); Garage behind
      the `s3` compose profile with `storage:garage-init` (17f).
- [x] Brand-scoped disk resolution (`media:{brandId}`, ADR-0043 point 6,
      ADR-0028 shape): `BrandStorageSetting` (credentials encrypted),
      `Storage\DiskResolver` as the one place that reads both kinds of disk
      name, and an operator-console screen at `admin/storage-settings`
      (`platform.manage-storage-settings`). Switching a brand's own storage
      off is refused while files still live in that bucket — clearing the
      settings would leave rows naming a disk nobody can build.
      Octane: the resolver is bound **scoped** and memoizes per request,
      because `FilesystemManager` caches disks in a singleton;
      `Storage::build()` returns an instance without registering it, which is
      what makes that safe. HeroBlock stopped taking MediaLibrary at
      construction for the same reason — block types live in a boot-time
      registry a long-lived worker shares.
- [x] Queued prefix cleanup on brand deletion (ADR-0043): `PurgeBrandMedia`,
      with the disks collected in Brand's `deleting` hook while the rows
      still exist. Media only — never the invoice archive.
- [ ] Real Konventix artwork. `AppLogoIcon.vue` and `public/favicon.*` are
      still the Laravel starter mark, and they are now the fallback every
      brand without its own logo wears.

- [ ] Orphan media cleanup job (ADR-0035 defers it): usage references exist,
      the job that garbage-collects unreferenced items after a grace period
      does not.
- [ ] Identity read model for the role-assignment and media screens: member
      rows and uploader columns show bare user ULIDs until a
      Platform-maintained read model (display name/email, fed by Identity's
      events per ADR-0004) exists. The screens are usable — ULIDs copy from
      the Users page — but this is the planned UX completion.
- [ ] resources/js/pages/admin/pages/Index.vue's "New page" dialog is a multi-field useForm with no ADR-0038 dirty guard — the same gap Commerce's product dialog had before tranche 3 closed it; add useUnsavedChanges(() => open && form.isDirty) plus the role="status" note, and reset the form on close so a discarded draft stops arming the guard.
- [ ] tests/Unit/ArchitectureTest.php's "App not to use Konventix" rule only inspects type-hinted dependencies, so a provider-level use import passes it silently — the rule's name promises more than it enforces. Either narrow the name or widen the check.

## Done

- [x] A theme export is a theme file somebody can author from (operator ask
      2026-09-22, release 0.8.1 Task 6). The export envelope is now
      `konventix.brand-theme/v2`: `tokens`/`darkTokens` carry the *effective*
      value of every `TokenCatalog` token in both schemes (a brand that has
      themed nothing downloads the complete default theme), `overrides`/
      `darkOverrides` name the ones the brand owns, and `authoringGuide`
      (`Data\ThemeAuthoringGuideData`, `ThemeContrastRuleData`,
      `ThemeTokenReferenceData`) is a reference block with prose, per-token
      label/group/defaults and the BFSG pairs plus the 4.5:1 minimum, which
      moved to `TokenCatalog::MIN_CONTRAST_RATIO` so the file states the
      number `UpdateBrandTheme` enforces. Reading the file is a second Data
      object, `Data\ImportBrandThemeData` (ADR-0009, one per endpoint): it
      accepts the v2 and the v1 marker, stores only what a file marks as the
      brand's own, and treats a missing marker list as "all of these are
      mine" — which is exactly what a v1 file and a hand-written file mean —
      so a round trip never freezes the platform defaults into a brand. It
      declares no `authoringGuide`, so a mangled guide cannot refuse a file
      whose values are fine. `Edit.vue` says what the download contains.

- [x] Brand theme export and import (2026-09-21 bugfix round, Task 10).
      `Data\BrandThemeExportData` (`konventix.brand-theme/v1` envelope:
      `format` validated with `In`, both token maps, `exportedAt` and the
      brand slug as provenance only; `ofBrand()`, `toThemeData()`),
      `Theming\ThemeCanonicalJson` (keys sorted at every level, pretty,
      unescaped, trailing newline, empty maps as `{}` — a module-local copy
      of Resources' `PlanCanonicalJson`, since Platform cannot import it),
      `Actions\ExportBrandTheme`. `ThemeAdminController::export()` streams
      `<slug>-theme.json`; `import()` takes a `file` (no `mimes`, 256 KB),
      refuses a non-object with a `file` error and runs the rest through
      `BrandThemeExportData`, `BrandThemeData` and `UpdateBrandTheme`, so an
      unknown name, a bad colour or a failing contrast pair 422s like a save
      and the stored theme never changes on a refusal. Routes
      `admin.brands.theme.export`/`.import` (Wayfinder emits them as
      `exportMethod`/`importMethod`, reserved words). Host
      `resources/js/pages/admin/themes/Edit.vue`: plain `<a download>` for
      the export, an import file control on its own `useForm` with error bag
      `themeImport` (a refused file never shows under the token grid), a
      confirm before an import replaces a dirty draft (ADR-0038), and the
      save form's defaults reset to the imported maps on success. Tests in
      `ThemeImportExportTest`; `operatorWithThemeAdmin()` moved to
      `tests/Helpers.php` since two files call it.

- [x] "Operator Admin" follows the untouched-only re-sync rule (ADR-0045
      amendment 2026-09-13, hotfix): `MaterializeOperatorAdminRole`, run from
      `permissions:sync` for every operator and from `konventix:install`.
      Before, the role was granted everything once at install and never
      revisited, so every capability a later release added (all of
      `resources.*` from 0.6.0) was a 403 on the admin host with no editor
      to grant it from. `/` on the admin host now redirects to `/admin` —
      there is no brand and so no home page there.

- [x] `operators.signups_open` and `OperatorSettings::signupsOpen()` /
      `signupsForcedClosed()` (hotfix 2026-09-13): the operator's own switch
      for account creation on the Operator settings page, capped by
      `platform.signups.enabled` (`SIGNUPS_ENABLED`). Operator level, not
      brand level — an account is the person's (ADR-0016), so a signup on
      one brand host is an account on every brand. Identity enforces it;
      this module only owns the setting and its page. The page names the
      environment's cap beside the switch so a switch that cannot open
      anything is explained.

- [x] ADR-0048 (mail-and-receipts tranche): three new cross-module read
      doorways for branded mail, none of them an Eloquent import.
      `Permissions\PermissionHolders::userIdsWithPermission(brandId,
      permission): list<string>` (point 3, Task 4) — a pure `DB::table()`
      query over spatie's own pivots, no Eloquent `Role`/`Permission` model,
      no Identity `User` import; answers "which users hold this permission
      on this brand". `Operators\OperatorSettings::
      adminBaseUrlForBrand(brandId): ?string` — `scheme://{admin_host}` for
      the brand's operator, the same doorway shape `sellerForBrand()`/
      `contactEmailForBrand()` already use; null when the brand has no
      operator or no `admin_host` set. Commerce's operations notice (a
      sold-out variant, a failed payment) is the first caller of both — it
      needs an absolute console link and a recipient list with no user
      model of its own. `Footer\FooterLinks::legalLinksFor(brandId):
      array{imprintUrl, privacyUrl}` (point 4, Task 1) — a plain read
      service reading the same `FooterLink` rows the page footer does,
      picking the first URL-kind entry whose label reads as imprint or
      privacy and returning it absolute via `BrandUrl`; `FooterLink` itself
      stays a Platform model other modules may not import, and Messaging's
      branded mail layout is the first caller through this doorway.

- [x] ADR-0047 point 5, Task 12: the `ensure-waiting-room` route middleware
      alias and its contract. `src/WaitingRoom/WaitingRoomGate` (`guard(Request):
      ?Response` — null passes, a non-null response blocks) and
      `Http/Middleware/EnsureWaitingRoomAdmission`, registered beside
      `ensure-module`/`ensure-feature`
      (`PlatformServiceProvider::registerRouteMiddleware()`). Resolves the
      gate from the container lazily (`app()->bound(...)`), never
      constructor-injected — a module applying the alias must never be
      broken by Commerce (the real binder) not having loaded first;
      `tests/WaitingRoomAliasTest.php` proves the pass-through directly.
      `BrandFeature`/`BrandFeatureData` grew a second extension point —
      `settingsComponent`/`settingsProps` — for a feature with its own
      settings to show on the brand edit page: a registered-component name
      plus a closure over the explicit, route-bound `Brand`
      (`BrandAdminController::edit()` already had it; now passed through
      to `BrandFeatureData::ofFeature()`), resolved only while the feature
      is `active`. Deliberately not a new `SlotRegistry` slot: that
      registry's feature gate reads the *ambient* `BrandContext::current()`,
      which the admin host never resolves (ADR-0029) — reusing the
      Features card's own working mechanism (an explicit brand, already
      correct there) was the smaller, safer change. Commerce's
      `commerce:waiting-room` feature is the first (and so far only)
      consumer, in its own `TODO.md`.

- [x] `MediaLibrary` grew two read methods for modules other than Platform:
      `belongsToBrand()` (the ownership check a picker's save needs) and
      `pickableImages()` (a brand's images, ready for `MediaPicker.vue`).
      Commerce's product editor (Task 7) needed exactly what
      `StaticPageAdminController::edit()` builds inline for its own media
      picker, but a straight copy would have queried `MediaItem` directly
      from outside Platform — a boundary `tests/Unit/ArchitectureTest.php`
      enforces, since `MediaItem` is not one of the Platform primitives
      (`Brand`, `Role`, `Permission`, `Concerns\*`, `Scopes\*`) that test
      lets a module import. `MediaLibrary` already existed as "the sanctioned
      doorway" for everything else about a media reference; these two
      methods extend that doorway to ownership and listing rather than
      leaving every future module to rediscover the same wall.

- [x] Two things the first click-through found. "Add block" in the
      composer was a dead click: a block type's `defaultData` arrives as an
      Inertia prop, i.e. a Vue reactive proxy, and `structuredClone` refuses
      proxies — the handler threw before it emitted. `freshBlockData()` now
      copies the default through JSON, which is what the payload is anyway.
      And the footer's per-brand forms were reachable only through the
      console's brand selector, which read as "no per-brand footer at all";
      the page now carries its own brand picker (defaulting to the console
      selection, like the roles page) and a save keeps the picked brand. The
      shape is unchanged and deliberate: the operator-wide entries cannot be
      overridden per brand (ADR-0001), a brand adds its own after them.

<!-- Move completed items here with the PR that finished them. -->

- [x] Slice 21b — footer content, closing what 21a left as a reserved slot.
      A brand's own social links now sit beside the operator's imprint
      (`footer_links.brand_id`, nullable, exactly the column the table was
      designed to grow — the operator-wide rows still answer for every
      brand, ADR-0001); footer columns (`footer_columns`) are small block
      compositions rather than a rich-text field, reusing the 21a
      composer and `ValidatesBlockComposition` so a column edits the same
      way the landing page does and inherits the media library (ADR-0035)
      and the token rule (ADR-0008); and a new `social-links` block type
      gives a column something to hold besides text and a hero image.
      The cookie-settings dialog fills the slot the `dialog` link kind
      reserved: `Cookies\CookieRegistry` is a boot-time registry every
      module declares its own cookies into through the new
      `registerCookies()` provider hook, the same declare-only pattern as
      `registerBlockTypes()`/`registerSlots()`/`defaultRoles()`, and the
      dialog renders from that registry rather than from a hand-maintained
      list — so a module that ships a cookie and forgets to declare it is
      a gap the dialog itself makes visible, not one only a regulator
      finds.
      The legal reading: § 25 Abs. 2 Nr. 2 TDDDG exempts a cookie only when
      it is strictly necessary for a service the person explicitly
      requested, which a persistent UI preference is not on its own; the
      DSK's Orientierungshilfe Telemedien is explicit that consent must
      precede setting anything beyond that exemption. WP194 draws the
      line the dialog implements: §3.6 treats a UI-customisation cookie
      (dark mode, sidebar state) as exempt for the duration of one
      session, and needing consent only once it persists longer — which
      is why disabling functional storage clears `appearance` and
      `sidebar_state` and the next choice sets a session-only cookie
      instead; §3.2 is explicit that a persistent login is never in that
      exempt set, which is the Steam fix in Identity's own `TODO.md`
      entry above. The consequence worth stating plainly: a module that
      sets a cookie and declares nothing is a bug the dialog makes
      visible.
      One correction alongside it: the roadmap's slice 21 entry said "the
      editor already owes ADR-0038's dirty guard" as something still
      owed — it was stale, the footer editors already had the guard
      before this slice touched them (`FooterLinkListForm.vue`,
      `FooterColumnsForm.vue`), fixed in `docs/ROADMAP.md` rather than
      repeated here.

- [x] Slice 21a — the static-page editor. A page's block composition is
      now written and edited, not just seeded: every shipped block type
      (`text`, `hero`, `cards`, Event's `event-hero`/`countdown`,
      Content's `latest-news`) implements the new `HasBlockEditor`
      interface, naming the label, editor component and default data the
      composer's "Add block" menu needs without a field-schema DSL;
      `ValidatesBlockComposition` validates a submitted block list against
      each type's data class on every write, with the page's brand in
      context since the admin host resolves none; `SaveStaticPage` diffs a
      page's media references before and after a save and calls
      `MediaLibrary::recordUsage`/`releaseUsage` for what changed, closing
      the gap the media-usage entry above left open — a page's blocks now
      record what they reference, same as a brand's marks. Frontend:
      `BlockComposer.vue` knows how to add, reorder (drag or the grip's
      arrow keys, ADR-0042) and remove a block and hand each one to the
      editor its type named through `resolveRegisteredComponent`, and
      knows no field of any block itself; the new
      `composables/useDragReorder.ts` factors the reorder gesture out of
      `AdminFavoritesNav.vue` so the composer and the sidebar favorites
      share one implementation. Six editors ship behind it —
      `platform/blocks/editors/{Text,Hero,Cards}Editor`,
      `event/blocks/editors/{EventHero,Countdown}Editor`,
      `content/blocks/editors/LatestNewsEditor` — each `defineProps<BlockEditorProps<T>>()`
      knowing only its own type's data shape, `MediaPicker` for the hero
      image, and a native `<select>` over the console's event sources for
      the two Event blocks. `admin/pages/{Index,Edit}.vue` replace Task
      10's stub pages: the index is the `DataTable`/`DataTableToolbar`
      pattern with a brand filter seeded from the console selection, the
      editor is one `useForm` covering title/slug/published/blocks behind
      the ADR-0038 unsaved-changes guard, with the block errors the server
      keys `blocks.{i}.data.{field}` stripped per block before reaching
      each editor's own `errors` prop.

- [x] Slice 19 — default role sets (ADR-0045). A module declares its
      defaults beside its `Permission` enum through the `defaultRoles()`
      hook on `ModuleServiceProvider` and Platform's new `DefaultRole` value
      object (`::of()` for a module's own capabilities, `::everything()` for
      Platform's own "Brand Admin", expanded against the whole catalogue at
      materialization time since Platform may name the catalogue but not
      another module's enum). Every role row now carries `default_key` and
      `customized_at` — the marker `MaterializeDefaultRoles` reads to tell a
      module's default from an operator's role, and to stop rewriting one
      the operator has since edited or renamed; a name collision with an
      operator-made role is reported and skipped, never hijacked.
      Materialization runs from three places: `permissions:sync` (every
      brand, so a capability added to an enum reaches every untouched
      role), `UpdateBrandModules` (the activated module's set, the moment
      it goes on), and `CreateBrand` (the always-on modules' sets, from the
      brand's first minute). Five sets shipped: Platform's "Brand Admin"
      (everything), Registration's "Registration Desk", Admission's
      "Entrance Staff", Content's "Content Editor" (every capability — it
      has none narrower to withhold), and Competition's "Tournament Admin".
      The roles console shows which role is which: a "default · `<module>`"
      badge, "· customized" once an edit has been saved, and the edit
      screen names the module and warns that the next sync leaves a saved
      edit alone. The demo seeder no longer hardcodes its three roles by
      prefix-matching permission names — it materializes the same defaults
      and hands three of them to a member each. Six stale claims corrected
      alongside it: four enum docblocks and two TODOs that said
      `permissions:sync` did not exist or did not pick up their catalogue,
      and Competition/Resources/Staffing's TODOs, which still asked for
      capability enums that had already shipped.

- [x] The operator dashboard is composed through the `admin.dashboard` UI slot
      (ADR-0003 enforcement point 3), like the per-event dashboard already
      was, and Platform contributes the first card on it: Pulse, Horizon and
      the health dashboard behind `ViewDiagnostics`; Telescope behind
      `ViewRequestLog`, and only where it is installed and outside
      production, because a request log is the power to read other people's
      request bodies. The slot's closure receives the viewing user as
      context, so a module filters its own card by capability without the
      console knowing what it filtered. Health also joined the console
      sidebar, which had the route and no link.

- [x] Every cross-brand list defaults its brand filter from the console
      selection: `HasDataTableQuery::resolveSelectionFilter()` is the one
      place that says how (absent → the selection, `all` → no filter), and
      the roles page reads the same pick as its required brand. The
      DataTableToolbar's per-page select finally has a label.

- [x] The console remembers which brand and event the operator is looking at.
      `ConsoleSelection` reads and writes two session keys on the admin host —
      the console has no brand, so this is never a brand cookie (ADR-0029) —
      and holds no state of its own, which is what lets a long-lived Octane
      worker resolve it fresh per request instead of leaking one operator's
      pick into the next. Platform owns the selector but may not read Event's
      tables, so the options arrive through `ConsoleEventSources`, a registry
      modules push into from their own provider via the new
      `registerConsoleEventSources()` hook; no source registered means no event
      selector at all, which is what a build without the Event module gets.
      `BelongsToBrand::scopeForBrand()` is the one greppable brand *filter*
      over a query that has already escaped the scope (ADR-0015: reads are
      filters), kept deliberately apart from the scoping mechanism ADR-0010
      forbids writing by hand. Nothing reads the pick yet — the lists follow.

- [x] A filter contract for the operator console's lists.
      `HasDataTableQuery` knew `search`, `sort`, `dir` and `per_page`; every
      other filter was a hand-rolled query parameter and a hand-rolled
      `<select>`, three pages in. `resolveFilters()` declares a list's
      filters and, where the values are a closed set, the values each
      accepts — anything else in the query string is dropped there instead
      of reaching the query — and `currentFilters()` echoes the resolved set
      back to the page, where `DataTableToolbar` renders that same
      declaration. Registrations is the first list on it, status resolved
      from `RegistrationStatus` rather than five hand-typed `<option>`s; the
      brand and event filters that follow need no page of their own.

- [x] A brand's marks are usage references. `UpdateBrand` records a
      `MediaUsage` row per item the brand wears and releases the ones it
      takes off, diffed as sets rather than per field because one file may
      be both logo and favicon. Before this the media admin could delete a
      logo out from under every page — `DeleteMediaItem` refuses only while a
      usage row exists, and until now nothing outside the test suite wrote
      one. That makes Brand the first real writer of the usage table.

- [x] A third navigation area, `AREA_ACCOUNT`, for the signed-in account's
      own things — rendered in the account menu rather than the topbar, and
      shared only when there is a user to render it for. A separate area
      rather than a flag on the brand one: these entries need no capability
      precisely because the menu holding them never renders for a guest.

- [x] `SlotRegistry` contributions may pass a closure instead of a literal
      prop-bag, which the rendering controller resolves with that page's
      context. The admin dashboards' cards fetch their own data client-side
      and needed none; a *public* page cannot work that way, because a call
      to action that appears one network round trip after the page is not
      one. Resolution happens after the activation filter, so an inactive
      module's closure never runs — it is the half that queries.

- [x] Brand identity assets: `logo_media_id`, `logo_dark_media_id` and
      `favicon_media_id` on `brands`, all media-library references
      (ADR-0035), resolved per request by `BrandIdentityResolver` and shared
      as the `brand` Inertia prop plus a `$brandIdentity` view variable —
      the root template's favicon and `<title>` are no longer `TODO(brand)`.
      One fallback level only, the bundled artwork: operator-wide defaults
      were considered and dropped, because media rows are brand-owned and
      the operator owns no brand. Light/dark marks swap by CSS, so the right
      one is in the markup before hydration.

- [x] The brand shell is a topbar, not a sidebar. `AppTopbarLayout` replaces
      `AppLayout`/`AppSidebarLayout` and absorbs `PublicTopbar`, so the
      landing page, static pages and member pages all wear one chrome; the
      operator console keeps its sidebar and gains no topbar. Its navigation
      is the first consumer of `NavigationRegistry`'s `AREA_BRAND`, which
      until now nothing read — the hardcoded "Home" array is gone and any
      module can contribute brand navigation from its own provider.

- [x] Operator footer (`/admin/settings/footer`, gated by
      `ManageFooterLinks`): `footer_links` rows the operator edits as one
      ordered list, rendered by `AppFooter` on every brand-facing page
      beside the version and the Konventix credit. Entries are operator-wide
      — one legal entity answers for all its brands — and a nullable
      `brand_id` can be added later without reshaping anything.

- [x] Per-brand dark-mode token overrides (slice 15b). A second column,
      `theme_tokens_dark`, rather than reshaping `theme_tokens` into
      `{light, dark}`: the tidier shape needed a data migration over every
      brand's palette and changed the meaning of a column three places already
      read, and for a colour map that trade was not worth it. The light map
      still lands on `:root`, the dark one on `.dark`, and the editor's scheme
      switch changes the defaults behind the inputs as well as the values in
      them — judging a dark accent against the light default is how a brand
      ends up with an unreadable dark mode.

- [x] TokenCatalog's own defaults now pass BFSG in **both** schemes, and a test
      holds them there. The three light status pairs this file used to list had
      already been fixed; turning the same guard on the dark defaults for the
      first time found `danger-contrast` on `danger` at 3.62:1 — white text on
      a bright red, where `success` and `warning` had long since switched to
      dark text on their bright fills. Dark `danger-contrast` now matches them
      (4.74:1). app.css and TokenCatalog were edited together, as that file's
      docblock requires.

- [x] BFSG keyboard pass over the console (slice 16). Contrast had a guard
      (ADR-0008) and nothing else did, and the gaps were all in one place: the
      shared `DataTable`. Sortable column headers were `@click` on a `<th>` —
      no keyboard access to sorting anywhere in the console; the pager's arrows
      were icon-only `Button`s a screen reader announced as "button"; and
      `rowClickable` was the *only* way into a brand, a user or an order,
      which a `<tr>` cannot offer a keyboard at all. Sorting is a `<button>`
      inside a `scope="col"` header with `aria-sort` now, the pager arrows have
      names, and the three tables' first column is a real `<Link>` — which also
      buys middle-click and open-in-new-tab. Both shells gained a skip link
      (WCAG 2.4.1) over a `<main id="main-content" tabindex="-1">`, sidebar rows
      announce `aria-current="page"`, and app.css honours
      `prefers-reduced-motion`. Recorded in `.ai/rules/js.md`, because these are
      the mistakes that get made again.

- [x] Operator settings page (slice 15b): `/admin/settings/operator`, behind a
      new `platform.manage-operator` capability. The legal seller identity every
      invoice is issued under (ADR-0030) existed only as a seeder row — Commerce
      read it, ZUGFeRD printed it, and correcting a typo meant tinker. No
      `{operator}` route parameter: the admin host resolves exactly one
      (ADR-0001, ADR-0029), and a parameter would invent a way to edit another.
      `admin_host` is deliberately not editable there — changing the address the
      console answers on, from a page served on it, is how you lock yourself out.

- [x] `brands.operator_id` is NOT NULL (slice 15b). It was nullable "until
      provisioning exists"; provisioning exists. The nullability was not
      harmless: a brand without an operator has no seller, so settlement threw
      mid-invoice on a real order rather than when the brand was created. The
      migration adopts any orphans into the single existing operator and refuses
      to run if there are orphans and no operator — a row saying who invoices
      come from is not something a migration may invent. `operator_id` stays out
      of `$fillable` on purpose, so nothing from a request can move a brand to
      another operator; the seeders associate through the relation instead.

- [x] `Brands\BrandUrl` — the origin a brand answers on, so console screens
      can link into a brand's public site. The admin host resolves no brand
      (ADR-0029), so `route()` there points back at the console; this reads
      the brand's primary domain and carries the current request's scheme and
      port, and resolves a whole page of brands in one query. First consumer
      is Content's editorial console.

- [x] Module-local Inertia page resolution (Slice 1): a custom `resolve`
      callback in `resources/js/app.ts` merges the host's
      `resources/js/pages` with every module's
      `app-modules/*/resources/js/pages` by identical relative page name
      (dev-time duplicate detection); `Modules\InertiaPageChunk` mirrors the
      mapping server-side for the root template's eager `@vite` page-chunk
      preload; `config/inertia.php` `pages.paths` globs module dirs for
      testing assertions. Companion convention for backend-named components
      (blocks, UI slots): `resources/js/lib/moduleComponents.ts` resolves
      `<module>/<path>` names over `app-modules/*/resources/js/components`,
      replacing the hardcoded maps in `StaticPage.vue` and Event's admin
      dashboard — block component names are now module-prefixed
      (`platform/blocks/TextBlock`, `event/blocks/CountdownBlock`).
      Module-owned pages/components moved home: Registration's form page +
      stats card, Admission's stats card, Event's admin pages + block
      components, Commerce's orders page, Platform's block components.
      `StatCard` stayed host-level — other modules' cards import it, so it
      is shared shell UI.
- [x] Media library per ADR-0035 (decision log Round 6, D-CNT-05):
      `MediaItem` + `MediaUsage` models on the new public `media` disk,
      `Media\MediaLibrary` as the sanctioned ULID→URL read/reference API
      (`urlFor`, idempotent `recordUsage`/`releaseUsage`),
      GD-based `thumb` variant (no imaging dependency), upload/delete
      Actions (delete refused while referenced), operator-console screen
      (`admin/media`, nav icon `image`, `platform.view-media`/`manage-media`
      capabilities). `tests/MediaLibraryTest.php`.
- [x] Role-assignment screen per decision log Round 6 (D-BATCH):
      `RoleAssignmentController` + `admin/roles` page (brand picker, roles
      with capability chips and member ULIDs, assign/revoke by user ULID);
      `Actions\AssignRoleToUser`/`RevokeRoleFromUser` write spatie's morph
      pivot with the explicit `brand_id` team column — no Identity model
      import anywhere in `src/` (the model class behind the pivot comes
      from the auth provider config). `tests/RoleAssignmentTest.php`.

- [x] `permissions:sync` command (`Console\Commands\SyncPermissionsCommand`,
      ADR-0013): walks every registered module's `Permission` enum, upserts
      rows, flushes the spatie cache, reports orphans without deleting.
      (This item lingered here after the command shipped with the operator
      console — checked off during the settlement-chain iteration.)
- [x] Operator settlement identity + brand currency (settlement-chain
      iteration): `operators` gained postal address, `vat_id`,
      `tax_number`, and the §19 UStG `small_business_scheme` election
      (operator-level by law, ADR-0030); `brands.currency` (fixed per
      brand, decision log Round 6 D-BATCH). `Operators\OperatorSettings` +
      `Data\OperatorSellerData` are the sanctioned scalar doorway Commerce
      uses for invoice seller identity — the cross-module model allow-list
      stays untouched.

- [x] Admin shell upgrade + data-table pattern ported from LanCore (ADR-0029).
      `AdminLayout.vue` rebuilt on the shadcn sidebar primitives (was a bare
      `<aside>`): `components/admin/AdminSidebar.vue` groups the shared
      `admin.navigation` prop by `module` into one collapsible
      `AdminNavGroup.vue` per module (label = humanized module name), with a
      live client-side search (`AdminSidebarSearch.vue`) that filters items
      and force-expands matching groups. Group-open state is local/session
      only — no server persistence, unlike LanCore's
      SidebarGroupStateController (kept out of scope).
      Favorites: `Models\AdminFavorite` (ULID pk, `user_id` plain ULID
      indexed, no FK — cross-module reference to Identity, ADR-0014;
      unique(user_id, url)) + `Data\AdminFavoriteData` +
      `Http\Controllers\Admin\AdminFavoriteController` (store/destroy,
      isolated per user_id) + `routes/admin/favorites.php` (new file,
      auto-loaded by ModuleServiceProvider alongside `platform.php`).
      `HandleInertiaRequests` shares `admin.favorites` (`{id, label, url}[]`)
      for the current user. Frontend: `composables/useAdminFavorites.ts`
      (pin/unpin by url, since a NavigationItem has no other stable id),
      `components/admin/AdminFavoritesNav.vue` +
      `components/admin/AdminNavLink.vue` (pin toggle per nav row).
      Data table: `composables/useDataTable.ts` (query-param convention
      `search`/`sort`/`dir`/`per_page`) +
      `components/admin/DataTable.vue` (generic, `@tanstack/vue-table`
      v8.21, manual sort/filter/pagination, footer "Showing X–Y of Z") +
      `components/admin/DataTableToolbar.vue` (search + per-page + actions
      slot). `Http\Concerns\HasDataTableQuery` trait shared by
      `BrandAdminController::index()` and Identity's
      `UserAdminController::index()` (their `Gate::authorize` calls
      untouched) — both now paginate and accept the same query params;
      `admin/brands/Index.vue` and `admin/users/Index.vue` refit onto
      `DataTable`/`DataTableToolbar` with `columns.ts` column defs.
      Deviation: `@tanstack/vue-table` was pinned to `^8.21.3` (LanCore's
      version) instead of the `npm install`-resolved `^9.x` — v9 is a
      pre-release-style rewrite (`useTable` instead of `useVueTable`, no
      `getCoreRowModel`/`FlexRender` in the familiar v8 shape) incompatible
      with the established composable pattern.

- [x] Drag-reorderable sidebar favorites (ADR-0042), the ordering half of the
      favorites feature LanCore never had. `admin_favorites.position`
      (`unsignedSmallInteger`, migration backfills existing rows in their
      previous alphabetical order; deliberately no `unique(user_id, position)`
      — a reorder rewrites row by row and would trip it mid-write) +
      `AdminFavorite::scopeInUserOrder()` (position, then label as
      tie-breaker), which `HandleInertiaRequests` now shares through instead of
      `orderBy('label')`. `store()` appends at `max(position) + 1`, so pinning
      never disturbs an arranged list. New `reorder()` action +
      `PUT /admin/favorites/order` (`admin.favorites.reorder`) taking the whole
      id list top-first (`Data\AdminFavoriteOrderData`, `ids.*` ulid):
      positions are derived from the list index server-side, foreign ids are
      filtered out rather than fatal, absent ids keep their position.
      Frontend: `useAdminFavorites().reorder(ids)` and a rewritten
      `AdminFavoritesNav.vue` — native HTML5 drag-and-drop (no new dependency),
      local list copy reordered live on `dragenter`, one persist on drop, and a
      grip handle with ArrowUp/ArrowDown keyboard parity plus an sr-only hint.
      `Link` carries `draggable="false"` so the browser's native link drag does
      not hijack the row's.

- [x] Brand theme editor, closing the theming half of the ADR-0008 backlog
      item (server-side token injection already existed — see ResolveBrand
      below). `Theming\TokenCatalog` (the overridable raw tokens, grouped,
      with hardcoded light/dark defaults kept in sync by hand with
      resources/css/app.css) + `Theming\ContrastRatio` (pure WCAG
      relative-luminance math over hex/`rgb()`/`hsl()`, unit-tested).
      `Actions\UpdateBrandTheme` validates token names against the catalog,
      values as colors, and the six ADR-0008 text/surface pairs at 4.5:1 —
      refuses the save (`ValidationException` naming the pair and the
      measured ratio), never warns. `Http\Controllers\Admin\ThemeAdminController`
      + `routes/admin/themes.php` (`admin.brands.theme.edit`/`.update`),
      gated on `platform.manage-brand-theme`. `Data\BrandThemeData` (form),
      `Data\ThemeCatalogData`/`ThemeTokenGroupData`/`ThemeTokenDefinitionData`/
      `ThemeContrastPairData` (read-side, TypeScript-generated) and
      `Data\BrandSummaryData`. Host `resources/js/pages/admin/themes/Edit.vue`
      + `resources/js/components/admin/theme/` (grouped color-picker grid,
      live preview panel using only semantic Tailwind classes, and a
      client-side contrast report mirroring the server rule in
      `resources/js/lib/contrast.ts`). `Brand.theme_tokens` stays a single
      map applied to `:root`; see Backlog for the `.dark` follow-up.
- [x] `hero` and `cards` block types (`Blocks\Types\HeroBlock`/`CardsBlock`,
      `Data\Blocks\HeroBlockData`/`CardsBlockData`/`CardItemData`), registered
      alongside `text` in `PlatformServiceProvider::registerBlockTypes()`.
      `Blocks\ResolvesBlockProps` is a new, optional interface a `BlockType`
      may implement when its props need server-side resolution beyond its
      stored payload (Event's `event-hero`/`countdown` are the first
      consumers) — added instead of changing the `BlockType` contract itself,
      since most block types need no resolution at all.
      `Blocks\StaticPageRenderer` extracted from `StaticPageController` (block
      list → `{key, component, props}`, checking `ResolvesBlockProps`), shared
      with the `/` landing route in host `routes/web.php`: a brand's
      published `home`-slug page renders through the same pipeline as
      `/pages/{slug}`; no page falls back to the minimal Welcome page.
      `PublicTopbar` (host `resources/js/components/PublicTopbar.vue`,
      generalized from LanCore) integrated into both `StaticPage.vue` and
      `Welcome.vue`.
- [x] `ResolveBrand` resolution and wiring: prepended to the `web` group by
      PlatformServiceProvider, so every web request runs in a brand or 404s;
      shares the brand's theme tokens with the root template; base TestCase
      creates a localhost brand for HTTP tests. Caddy on-demand TLS `ask`
      endpoint (`/caddy/ask`, outside the `web` group) refuses hosts no brand
      answers on. Dev web role runs FrankenPHP via Octane (`BrandContext` is
      container-scoped, not singleton, for worker safety).
- [x] Operator model, migration, factory (ADR-0001); `brands.operator_id`
      nullable until provisioning exists, backfilled by the seeder.
- [x] Brand-scoped session configuration: the session cookie name carries the
      brand slug, set by ResolveBrand before StartSession.
- [x] Pennant gating at all four enforcement points — the fourth
      (`Modules\Concerns\ShortCircuitsInactiveModule` for listeners) joined
      routes, navigation filter and slot gate. **Found and fixed along the
      way:** ResolveBrand was never actually in the `web` group — the HTTP
      kernel syncs group definitions after providers boot, silently dropping
      the provider's prepend. It is now wired in bootstrap/app.php.
- [x] Block-based static brand pages: `Blocks\BlockType` contract +
      `BlockRegistry` (registered via the new `registerBlockTypes()` provider
      hook), Platform's `text` block as reference, render pipeline in
      StaticPageController at `/pages/{slug}`, unknown/inactive types skipped.
- [x] laravel-data → TypeScript pipeline: TypeScriptTransformerServiceProvider
      scans `app/` + every module's `src/Data` and `src/Enums` into
      `resources/js/types/generated.d.ts`; regenerate with
      `composer types:generate`.
- [x] Reverb serves brands from the database (ADR-0025):
      `Realtime/BrandApplicationProvider` (driver `brands`, selected via
      `REVERB_APP_PROVIDER`) maps each brand row to a Reverb app — ULID as app
      id, key/secret provisioned on create, `allowed_origins` pinned to the
      brand's domains. `Realtime/BrandBroadcasting` signs outbound broadcasts
      with the resolved brand's app on both the request path (ResolveBrand)
      and the queue restore path; the per-brand key reaches Echo via Inertia
      shared props.
- [x] `media:regenerate-variants` — the backfill for a variant invented
      after the uploads it applies to (round 4, Task 28; `ticket_banner` is
      the first, hence the 0.7.0 post-deploy step in `docs/operations.md`).
      `Console\Commands\RegenerateMediaVariantsCommand` walks
      `MediaItem::withoutBrandScope()` over the raster rows — a console run
      resolves no brand (ADR-0029) — and creates nothing: every row is
      rewritten in the brand it already belongs to. `--only=` (validated
      against `ThumbnailGenerator::VARIANTS`, which is now what `generate()`
      takes a subset of), `--brand=` by ULID or slug, `--missing` on by
      default. Idempotent at the row: only the absent variants are cut, and
      a map that does not change leaves `variants_generated_at` alone, so a
      picture too small for the band is not re-encoded on every pass.
