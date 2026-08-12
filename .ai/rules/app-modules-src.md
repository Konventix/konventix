---
paths:
  - 'app-modules/*/src/**'
---

# App Modules Src

## BrandScope hardens to zero rows on the admin host — escape it in relation counts too
On the operator admin host BrandScope adds WHERE 1=0 (ADR-0029 hardening). withoutBrandScope() on the parent query is NOT enough: relation queries, withCount() subqueries and $model->relation()->count() re-apply the scope on the related model and silently read 0/empty. Any admin-host code touching brand-owned relations must escape per relation: withCount(['x' => fn ($q) => $q->withoutGlobalScope(BrandScope::class)]) or RelatedModel::withoutBrandScope()->where(...). This deleted referenced media files in dev before a test caught it.
