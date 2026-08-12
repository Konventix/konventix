<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

/**
 * Optional extension of {@see BlockType} for block types whose props cannot
 * be computed from the stored payload alone. Event's `event-hero` and
 * `countdown` blocks store only an event ULID and resolve the display
 * payload (name, wall-clock schedule, venue) server-side, from Event's own
 * model — that resolution happens inside the contributing module, never in
 * Platform, since no module may `use` another module's Eloquent models
 * (module-map.md "No cross-module Eloquent imports").
 *
 * A new interface rather than a change to {@see BlockType} itself:
 * `BlockType` is the contract every module implements, and most block types
 * (Platform's `text`, `hero`, `cards`) need no server-side resolution at all
 * — their stored data class already is the props shape.
 */
interface ResolvesBlockProps
{
    /**
     * Turn the block's stored `data` payload into the props its Vue
     * component receives. Implementations typically validate the payload
     * through their own `dataClass()` first, then look up whatever foreign
     * entity it references.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array;
}
