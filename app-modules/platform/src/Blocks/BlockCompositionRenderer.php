<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

/**
 * Turns a stored `{type, data}` block list into the `{key, component,
 * props}` shape the Vue side consumes. Extracted from StaticPageRenderer so
 * the footer's columns (slice 21b) render through exactly the same registry
 * gate and the same server-side resolution hook as a page.
 *
 * Blocks whose type is unknown or whose module is inactive for the brand are
 * skipped, not errors: a composition survives a module being switched off.
 */
class BlockCompositionRenderer
{
    public function __construct(private readonly BlockRegistry $registry) {}

    /**
     * @param  iterable<int, array{type?: string, data?: array<string, mixed>}>  $blocks
     * @return list<array{key: string, component: string, props: array<string, mixed>}>
     */
    public function render(iterable $blocks): array
    {
        $rendered = [];

        foreach ($blocks as $block) {
            $type = $this->registry->resolveActive((string) ($block['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = $block['data'] ?? [];

            $rendered[] = [
                'key' => $type->key(),
                'component' => $type->component(),
                'props' => $type instanceof ResolvesBlockProps
                    ? $type->resolve($data)
                    : $type->dataClass()::from($data)->toArray(),
            ];
        }

        return $rendered;
    }
}
