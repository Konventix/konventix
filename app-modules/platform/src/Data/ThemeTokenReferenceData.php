<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One catalog token as the theme *file* describes it (ADR-0008): what it is
 * called in the editor, which group it belongs to, and the platform default
 * it falls back to in each colour scheme.
 *
 * Sibling of ThemeTokenDefinitionData, which is the editor's screen version
 * of the same row. They are kept apart on purpose: the screen already knows
 * its groups (it renders one grid per group and keys the token by its name),
 * while a file has to carry the group and the name inside the entry for a
 * reader who has only the file. The token's own name is the map key in
 * ThemeAuthoringGuideData::$tokens, so it is not repeated here.
 */
#[TypeScript]
final class ThemeTokenReferenceData extends Data
{
    public function __construct(
        public string $label,
        public string $group,
        public string $groupLabel,
        public string $defaultLight,
        public string $defaultDark,
    ) {}
}
