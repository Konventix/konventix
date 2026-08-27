---
paths:
  - 'app-modules/*/src/**'
---

# App Modules Src

## BrandScope hardens to zero rows on the admin host — escape it in relation counts too
On the operator admin host BrandScope adds WHERE 1=0 (ADR-0029 hardening). withoutBrandScope() on the parent query is NOT enough: relation queries, withCount() subqueries and $model->relation()->count() re-apply the scope on the related model and silently read 0/empty. Any admin-host code touching brand-owned relations must escape per relation: withCount(['x' => fn ($q) => $q->withoutGlobalScope(BrandScope::class)]) or RelatedModel::withoutBrandScope()->where(...). This deleted referenced media files in dev before a test caught it.

## A Blade directive glued to a preceding/following word is left as literal text — MessageTemplate subjects and bodies both go through Blade::render()
Messaging's `TemplateRenderer` runs both the subject and the body through `Blade::render()` on a raw string. Blade's directive regex only recognises `@word` when it has a non-word boundary on both sides: `bezahlt@else` and `AAA@endif` (no space/newline before `@else`/`@endif`) do not match, so the directive is left as literal `@else`/`@endif` text and everything from the previous directive onward silently fails to compile as intended — `@if($p)\nA\n@else\nB\n@endif`, or the same with spaces around each directive, compiles correctly. This is core Blade behaviour, not a package interaction — always put whitespace or a newline around every directive in a template string. Prefer two template keys over one template branching on `@if($paid)…@else…@endif` anyway (see `commerce.invoice-issued` vs `commerce.invoice-paid`, `CommerceServiceProvider::registerMessageTemplates()`) — the caller (e.g. `SendInvoiceMail`) picks the key, and per-brand template overrides (`TemplateOverride`) stay branch-free.
