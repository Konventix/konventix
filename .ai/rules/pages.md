---
paths:
  - 'app-modules/*/resources/js/pages/**'
  - 'resources/js/pages/**'
---

# Pages

## Stateful forms get the unsaved-changes guard (ADR-0038)
Any edit or multi-field create form built on `useForm` must render an "Unsaved changes" note next to the submit button (`v-if="form.isDirty"`, `text-warning` token, `role="status"`) and call `useUnsavedChanges(() => form.isDirty)` from `resources/js/composables/useUnsavedChanges.ts`. Never hand-roll `router.on('before')` or `beforeunload` listeners in pages — the composable owns the exemption rules (non-GET, prefetch, and async visits pass through). Search boxes and filters are out of scope. When touching an existing stateful form that lacks the guard, add it in the same PR. See docs/decisions/0038-unsaved-changes-guard.md.
