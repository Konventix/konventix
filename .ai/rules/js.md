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
