<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a `resource_plan_versions` row came from (design §4.5). `Copy` is
 * reserved for the plan-copy feature that is out of scope in v1.
 */
#[TypeScript]
enum PlanVersionSource: string
{
    case Editor = 'editor';
    case Import = 'import';
    case Copy = 'copy';
}
