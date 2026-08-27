---
paths:
  - 'app-modules/*/src/**'
---

# App Modules Src

## BrandScope hardens to zero rows on the admin host — escape it in relation counts too
On the operator admin host BrandScope adds WHERE 1=0 (ADR-0029 hardening). withoutBrandScope() on the parent query is NOT enough: relation queries, withCount() subqueries and $model->relation()->count() re-apply the scope on the related model and silently read 0/empty. Any admin-host code touching brand-owned relations must escape per relation: withCount(['x' => fn ($q) => $q->withoutGlobalScope(BrandScope::class)]) or RelatedModel::withoutBrandScope()->where(...). This deleted referenced media files in dev before a test caught it.

## Never use @if/@else/@endif (or any block directive) in a MessageTemplate subject or body
Messaging's `TemplateRenderer` runs both the subject and the body through `Blade::render()` on a raw string (Commerce's `commerce.invoice-issued` template already proved subjects go through Blade, not just bodies). Livewire's `SupportMorphAwareBladeCompilation` precompiler is registered globally (`livewire.inject_morph_markers` defaults true, unconditional — not gated on Livewire actually rendering) and wraps every `@if`/`@unless`/`@foreach`/… and its matching closing directive with its own morph-tracking markers. Verified in isolation (`Blade::render('@if($paid)AAA@endif', [...])`, and even a plain `.blade.php` view file with the same one-liner) that this silently leaves the directive's own closing tag uncompiled — no exception, the rendered string is just empty, or a `ViewException` depending on the exact shape. This is not specific to `MessageTemplate` strings; it reproduces for any Blade content compiled through `Blade::render()` or `view()->make()` in this app. Do not fight it: branch by registering two template keys instead of one template with `@if($paid)…@else…@endif` (see `commerce.invoice-issued` vs `commerce.invoice-paid`, `CommerceServiceProvider::registerMessageTemplates()`) — the caller (e.g. `SendInvoiceMail`) picks the key. Plain `{{ $x ? 'a' : 'b' }}` ternaries inside a single `{{ }}` echo are safe (the precompiler only touches block *directives*, not echo expressions).
