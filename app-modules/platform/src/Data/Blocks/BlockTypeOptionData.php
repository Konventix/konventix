<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\HasBlockEditor;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the composer's add menu knows about one block type.
 */
#[TypeScript]
final class BlockTypeOptionData extends Data
{
    /**
     * @param  array<string, mixed>  $defaultData
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $editorComponent,
        public readonly array $defaultData,
    ) {}

    public static function ofType(BlockType&HasBlockEditor $type): self
    {
        return new self(
            key: $type->key(),
            label: $type->label(),
            module: $type->module(),
            editorComponent: $type->editorComponent(),
            defaultData: $type->defaultData(),
        );
    }
}
