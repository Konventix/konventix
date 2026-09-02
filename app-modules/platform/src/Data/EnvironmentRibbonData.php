<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The corner ribbon a non-production instance wears (GitLab #68), as the
 * shell receives it on the shared `environment` prop. Never built on
 * production — the prop is null there, and the component renders nothing.
 */
#[TypeScript]
final class EnvironmentRibbonData extends Data
{
    public function __construct(
        /** The environment as configured: `staging`, `local`, `undeclared`, ... */
        public string $name,
        /** What the ribbon shows — the configured label, or the name upper-cased. */
        public string $label,
        /** Any CSS color; the danger token by default. */
        public string $color,
        /** The release this instance runs: a tag or a commit. */
        public string $release,
    ) {}
}
