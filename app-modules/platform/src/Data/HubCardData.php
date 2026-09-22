<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One card as the live hub page receives it (design §7.1): the component
 * name the module registered and the props that component takes, already
 * resolved for this member at this event. `$payload` is whatever `Data` the
 * card's closure returned — the shell spreads it onto the component and
 * never looks inside, which is what lets Platform render a card it has
 * never heard of (ADR-0003). The transformer cannot name a type for the
 * abstract `Data` (it emits `undefined`), so the TypeScript side is spelled
 * out: a bag of props, spread with `v-bind`.
 */
#[TypeScript]
final class HubCardData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $component,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly Data $payload,
    ) {}
}
