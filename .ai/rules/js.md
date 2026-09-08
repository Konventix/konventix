---
paths:
  - 'resources/js/**'
  - 'app-modules/*/resources/js/**'
---

# Frontend (Vue, TypeScript)

## BFSG: keyboard reachability is the rule the console keeps breaking
A `<tr>` cannot take focus, so `DataTable`'s `rowClickable` is a mouse convenience only — a page that sets it MUST also render a real `<Link>` to the same destination in one of its cells, or the target is unreachable by keyboard (WCAG 2.1.1). The same trap in table heads: a sortable header is a `<button>` inside the `<th>`, never a `@click` on the `<th>` itself, and the `<th>` carries `scope="col"` plus `aria-sort`. Icon-only buttons need an accessible name — `aria-label`, or a `<span class="sr-only">` child. Sidebar nav rows need `aria-current="page"`; `is-active` only sets `data-active`, which styles the row and says nothing to a screen reader.

## BFSG: the public purchase flow is the surface the law means (ADR-0055)
The rules above came out of the console; the public and account pages target WCAG 2.1 AA regardless of the operator's exemption. There: a form error is linked to its field — the input carries `aria-invalid="true"` and `aria-describedby` pointing at the `InputError`'s id, and after a failed submit focus moves to the first invalid field (`useFormField` once #180 lands; until then wire the ids by hand rather than ship an unlinked error). Live regions announce state changes, never ticking values — `role="status"` for "your cart expires in two minutes", `role="timer"` *without* `aria-live` for the countdown itself. A time limit is visible, warned about and explained, and a cart hold is never extended (ADR-0055 point 4). Content in another language than the page gets its own `lang`; the page's `lang` follows the brand locale (#132). After a client-side navigation focus belongs on `<main id="main-content">` (#181) — no per-page focus hacks. A PDF is not the accessible form; the page and the mail are (ADR-0055 point 3).

## A `datetime-local` control is a wall clock; the database stores instants
Convert in the browser with `toInstant()` / `toLocalDateTimeInput()` from `@/lib/datetime`, never by string-munging the value (`value.replace('T', ' ')` sends the operator's digits and the server reads them in `config('app.timezone')`). On the server, normalise whatever offset arrives to UTC in a `…Instant()` helper on the Data class — `RegistrationTypeData::salesStartInstant()`, `PolicyVersionData::effectiveAtInstant()` — because a zone-less column stores whatever zone the Carbon instance carries. A field hint names the *reader's* zone (`browserTimezone`), never the application's.

Show a system timestamp with `formatDateTime()` from the same file; a Data class or controller sends it as `toIso8601String()`. Leave calendar dates (`dueOn`, `issuedOn`, `repayDueOn`) as dates — a deadline that moves with the reader is a new bug — and leave ADR-0027 event times as the organizer's wall clock. #202, #237, #238.

## Regenerate Wayfinder with --with-form or you silently delete `.form` everywhere
`vite.config.ts` configures `wayfinder({ formVariants: true })`, but
`php artisan wayfinder:generate` on its own does NOT generate them: it
rewrites every file under `resources/js/routes` and `resources/js/actions`
without the `.form` helper, and `npm run types:check` then reports
"Property 'form' does not exist" in a dozen auth, settings and page
components that nobody touched. Always
`vendor/bin/sail artisan wayfinder:generate --with-form`, or just let
`npm run build` / `npm run dev` do it.
