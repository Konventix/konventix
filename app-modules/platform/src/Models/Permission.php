<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A single capability row.
 *
 * DERIVED STATE — do not create or edit these by hand. Rows are a projection of
 * the modules' `src/Enums/Permission.php` catalogs, upserted by the
 * `permissions:sync` command (ADR-0013). To add a capability, add it to the
 * owning module's enum and re-sync.
 *
 * Exists only to give spatie's model a ULID key (ADR-0014).
 */
class Permission extends SpatiePermission
{
    use HasUlids;
}
