<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

/**
 * The editor half of a block type (ROADMAP slice 21a), optional beside
 * {@see ResolvesBlockProps} the same way that one was added: a type that
 * implements it appears in the composer's add menu with a label, ships its
 * own editor component (module-component-registry name, e.g.
 * `platform/blocks/editors/HeroEditor`), and says what a fresh block looks
 * like. No field schema DSL — the editor is a Vue component that knows its
 * own fields, and the Data class validates on save.
 */
interface HasBlockEditor
{
    public function label(): string;

    public function editorComponent(): string;

    /**
     * @return array<string, mixed>
     */
    public function defaultData(): array;
}
