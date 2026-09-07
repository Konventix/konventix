---
paths:
  - 'app-modules/*/resources/js/pages/**'
  - 'resources/js/pages/**'
---

# Pages

## Stateful forms get the unsaved-changes guard (ADR-0038)
Any edit or multi-field create form built on `useForm` must render an "Unsaved changes" note next to the submit button (`v-if="form.isDirty"`, `text-warning` token, `role="status"`) and call `useUnsavedChanges(() => form.isDirty)` from `resources/js/composables/useUnsavedChanges.ts`. Never hand-roll `router.on('before')` or `beforeunload` listeners in pages — the composable owns the exemption rules (non-GET, prefetch, and async visits pass through). Search boxes and filters are out of scope. When touching an existing stateful form that lacks the guard, add it in the same PR. See docs/decisions/0038-unsaved-changes-guard.md.

## A console list's "tabs" are toolbar filters
Operator-console index pages have no tab strip; every list narrows itself
through `DataTableToolbar` filter selects declared once on the backend
(`HasDataTableQuery::resolveFilters()`) and once as a `DataTableFilterDef`.
A filter whose label is the plural noun of the list ("Orders") reads
correctly in the toolbar's own "All {label}" option, so a saved view is a
one-option filter, not a new component. See the orders index's `view=reversals`
(ADR-0057 point 8).
