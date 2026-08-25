# Platform — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

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

## Done

<!-- Move completed items here with the PR that finished them. -->

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
      one. That makes Brand the first real writer of the usage table; the
      page blocks that reference media still record nothing, which the
      orphan-cleanup job in the backlog has to wait for as well.

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
      `brand_id` can be added later without reshaping anything. A `dialog`
      link kind reserves the cookie-settings slot: it resolves through the
      module component registry and renders nothing until something ships
      under that key.

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
