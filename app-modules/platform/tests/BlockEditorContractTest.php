<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Konventix\Platform\Blocks\BlockCompositionRenderer;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Models\StaticPage;

/**
 * The editor half of the block contract (ROADMAP slice 21a): every shipped
 * type describes itself to the composer, a stored payload is validated on
 * write for the first time, and the page's block list is a typed collection.
 */
it('offers every built-in type to the editor with a label and an editor component', function (): void {
    $this->withBrand();
    $editable = app(BlockRegistry::class)->editable();

    foreach (['text', 'hero', 'cards', 'event-hero', 'countdown', 'latest-news'] as $key) {
        expect($editable)->toHaveKey($key)
            ->and($editable[$key])->toBeInstanceOf(HasBlockEditor::class)
            ->and($editable[$key]->label())->not->toBe('')
            ->and($editable[$key]->editorComponent())->toContain('/blocks/editors/');
    }
});

it('validates each block against its data class and keys the errors by position', function (): void {
    $this->withBrand();
    $validator = new class
    {
        use ValidatesBlockComposition;

        public function run(array $blocks, ?array $allowed = null): array
        {
            return $this->validateBlocks(app(BlockRegistry::class), $blocks, $allowed);
        }
    };

    expect(fn () => $validator->run([['type' => 'hero', 'data' => ['subheading' => 'no heading']]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('blocks.0.data.heading'));

    expect(fn () => $validator->run([['type' => 'no-such-block', 'data' => []]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('blocks.0.type'));

    expect(fn () => $validator->run([['type' => 'hero', 'data' => ['heading' => 'x']]], allowed: ['text']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('blocks.0.type'));

    $valid = $validator->run([['type' => 'text', 'data' => ['heading' => null, 'body' => 'Hello']]]);

    expect($valid)->toBe([['type' => 'text', 'data' => ['heading' => null, 'body' => 'Hello']]]);
});

it('stores blocks as a typed collection and still renders them', function (): void {
    $this->withBrand();
    $page = StaticPage::factory()->published()->create([
        'blocks' => [['type' => 'text', 'data' => ['heading' => 'Hi', 'body' => 'there']]],
    ]);

    $page->refresh();

    expect($page->blocks)->not->toBeNull()
        ->and($page->blocks[0])->toBeInstanceOf(BlockData::class)
        ->and($page->blocks[0]->type)->toBe('text');

    $rendered = app(BlockCompositionRenderer::class)->render($page->blocks->toArray());

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['component'])->toBe('platform/blocks/TextBlock')
        ->and($rendered[0]['props']['body'])->toBe('there');
});
