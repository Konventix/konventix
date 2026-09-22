<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\CartLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line inside #219's undo window: removed from the cart, not yet gone.
 *
 * Deliberately **not** a `CartLineData`. That shape is built around the
 * figures the tax calculation produced for the cart — `lineGross`,
 * `unitAmountGross` — and a removed line is not in that calculation, so
 * every one of those fields would have to be invented for it. What the undo
 * banner has to say is what was taken out and how to put it back, which is
 * the label, the quantity and the id the restore route takes.
 *
 * `url` is where the label links, resolved by the controller exactly as it
 * is for a line still in the cart: the registration's own page. Null when
 * there is nowhere to point.
 */
#[TypeScript]
final class RemovedCartLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $label,
        public readonly int $quantity,
        public readonly ?string $url,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofLine(CartLine $line, ?string $url = null): self
    {
        return new self(
            id: $line->getKey(),
            kind: $line->kind->value,
            label: $line->label,
            quantity: $line->quantity,
            url: $url,
        );
    }
}
