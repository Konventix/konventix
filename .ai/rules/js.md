---
paths:
  - 'resources/js/**'
  - 'app-modules/*/resources/js/**'
---

# Frontend (Vue, TypeScript)

## BFSG: keyboard reachability is the rule the console keeps breaking
A `<tr>` cannot take focus, so `DataTable`'s `rowClickable` is a mouse convenience only — a page that sets it MUST also render a real `<Link>` to the same destination in one of its cells, or the target is unreachable by keyboard (WCAG 2.1.1). The same trap in table heads: a sortable header is a `<button>` inside the `<th>`, never a `@click` on the `<th>` itself, and the `<th>` carries `scope="col"` plus `aria-sort`. Icon-only buttons need an accessible name — `aria-label`, or a `<span class="sr-only">` child. Sidebar nav rows need `aria-current="page"`; `is-active` only sets `data-active`, which styles the row and says nothing to a screen reader.
