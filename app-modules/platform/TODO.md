# Platform — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Phase 1 — Platform carries most of the PoC risk, since every other module
depends on brand resolution and gating working.

- [ ] Operator, Brand and BrandDomain models with migrations (ULID primary keys,
      ADR-0014) and factories; operator data as rows, not config (ADR-0001).
- [ ] Tighten `brands.operator_id` to non-null once brand creation goes
      through an Operator provisioning flow.
- [ ] `BelongsToBrand` trait: global scope, automatic `brand_id` on create, and
      the `withoutBrandScope()` escape hatch behind `UseCrossBrandScope`
      (ADR-0010). Brand context serialized into queued jobs and restored in the
      worker; the base TestCase establishes a brand and resets the spatie team
      context.

## Backlog

- [ ] Object storage for the media library (ADR-0043, ROADMAP slice 17), in
      this order:
      1. `hero` block stores `imageMediaId` instead of a resolved
         `imageUrl`, resolved through `ResolvesBlockProps`/`MediaLibrary`,
         with a payload migration for existing pages. Prerequisite: stored
         URLs rot the moment the disk or asset host changes.
      2. S3 driver on the `media` disk, `public_direct` + asset `url` as
         explicit deployment config; `MediaLibrary::urlFor()` is the single
         branch point, resolution stays network-free.
      3. Public proxy route for the non-direct case (also the local-disk
         fallback), long immutable cache headers — keys are never
         overwritten.
      4. Queued variant generation whenever the disk is remote, with a
         pending state in `MediaItemData` and a UI placeholder.
      5. Brand-scoped disk resolution (`media:{brandId}`, ADR-0028 shape).
         Octane trap: `FilesystemManager` caches disks in a singleton — key
         per brand, never across requests.
      6. `storage:migrate` (copy → verify size+checksum → rewrite rows in
         batches → optional delete) and a storage doctor command covering
         every configured disk, the `public/storage` symlink, and an
         unauthenticated fetch that proves `public_direct` is true.
      7. Garage behind a compose profile as the dev backend; idempotent
         admin-API init command for the second bucket and website access.
- [ ] Queued prefix cleanup on brand deletion (ADR-0043): rows cascade,
      objects do not. Media only — never the invoice archive.
- [ ] Orphan media cleanup job (ADR-0035 defers it): usage references exist,
      the job that garbage-collects unreferenced items after a grace period
      does not.
- [ ] Identity read model for the role-assignment and media screens: member
      rows and uploader columns show bare user ULIDs until a
      Platform-maintained read model (display name/email, fed by Identity's
      events per ADR-0004) exists. The screens are usable — ULIDs copy from
      the Users page — but this is the planned UX completion.
- [ ] Per-brand dark-mode token overrides. `Brand.theme_tokens` is one map
      applied to `:root` only; the theme editor built for the light map does
      not yet let a brand override `.dark` separately. Same mechanism
      (TokenCatalog already carries both `light` and `dark` defaults per
      token) — needs a second map column or a `{light, dark}` shape and a
      tab in the editor.
- [ ] Fix TokenCatalog's own light-mode defaults for the three status pairs:
      `danger-contrast`/`danger` (3.61:1), `success-contrast`/`success`
      (3.53:1) and `warning-contrast`/`warning` (2.52:1) all fall short of
      the ADR-0008 4.5:1 minimum as currently hardcoded in
      resources/css/app.css. Found while building UpdateBrandTheme's contrast
      guard: checking every catalog pair unconditionally would refuse any
      brand's save the moment it touched an unrelated token, since the
      platform's own untouched defaults already fail three of six pairs. The
      guard therefore only checks pairs the save actually touches (see its
      doc comment) — a scoping decision, not a loophole, but it means the
      starter-kit palette itself is still not BFSG-compliant out of the box.
      This is separate from app.css's own "decide on the Konventix house
      palette" TODO; whichever palette lands needs to pass all six pairs on
      its own.

## Done

<!-- Move completed items here with the PR that finished them. -->

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
