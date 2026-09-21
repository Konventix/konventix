<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `PATCH /admin/support-requests/{request}/assignee` (design §9.2).
 *
 * A ULID or null, and null is a real value rather than a missing one:
 * handing a report back to the pile is the same act as taking it, and an
 * endpoint that could only assign would make "not mine after all" a
 * database edit.
 *
 * Whether the ULID belongs to somebody who may triage is the controller's
 * question, not a validation rule: the answer comes from
 * `PermissionHolders`, which knows about teams and roles, and repeating it
 * as an `exists` rule would be a second, weaker copy of it.
 */
#[TypeScript]
final class SupportAssigneeInputData extends Data
{
    public function __construct(
        #[Nullable, Ulid]
        public readonly ?string $assignedToUserId,
    ) {}
}
