<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

use Spatie\LaravelData\Data;

/**
 * One kind of block a static brand page can contain (ADR-0003 pattern: any
 * module may contribute types; Platform never knows the list).
 *
 * The data class is the single source of validation and generated TypeScript
 * for the block's payload (ADR-0009) — a block payload is never a free-form
 * array.
 */
interface BlockType
{
    /**
     * Stable identifier stored in the page's block list, e.g. `text`,
     * `competition-board`. Kebab-case, unique across all modules.
     */
    public function key(): string;

    /**
     * The owning module's kebab-case name. Blocks of gateable modules render
     * only where the module is active for the brand.
     */
    public function module(): string;

    /**
     * The Vue component that renders this block on the public page.
     */
    public function component(): string;

    /**
     * The Data class describing and validating the block payload.
     *
     * @return class-string<Data>
     */
    public function dataClass(): string;
}
