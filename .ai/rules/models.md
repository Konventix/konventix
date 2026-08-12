---
paths:
  - 'app-modules/*/src/Models/**'
  - 'app-modules/*/database/**'
---

# Models

## Models outside App\Models must wire their factory in both directions
Laravel's factory<->model guessing only works for the `App\Models` namespace. For every
module model state both directions explicitly, or `Class "App\<Name>" not found` blows up at
test time:

- Model: override `protected static function newFactory(): <Name>Factory` returning `<Name>Factory::new()`.
- Factory: set `protected $model = <Name>::class;`

Factories, migrations and seeders live inside the module — `app-modules/<name>/database/{factories,migrations,seeders}`,
namespaced `Konventix\<Name>\Database\Factories\` etc. The central `database/` directory is
host-level only.

Every model has a ULID primary key (`HasUlids`, `$table->ulid('id')->primary()`); no
auto-increment keys, and cross-module foreign columns are `ulid` with no FK constraint
(ADR-0014).

Trap: brand-owned models get `Konventix\Platform\Models\Concerns\BelongsToBrand`, which adds
the global `BrandScope` and stamps `brand_id` on create. Writing `where('brand_id', ...)` as
the scoping mechanism is the failure mode this replaces; the only escape hatch is
`Model::withoutBrandScope()` (ADR-0010). Users are deliberately *not* brand-scoped.
