<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A named bundle of capabilities, scoped to a brand (ADR-0013).
 *
 * Roles are DATA — an operator creates and edits them at runtime. That is the
 * half of the permission system that is deliberately not code; the capability
 * catalog they grant from lives in each module's `src/Enums/Permission.php`.
 *
 * Exists only to give spatie's model a ULID key (ADR-0014) and to keep the
 * brand relationship discoverable from Platform.
 */
class Role extends SpatieRole
{
    use HasUlids;
}
